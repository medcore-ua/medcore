<?php

/*
 *
 *                      _
 *   _ __ ___   ___  __| | ___ ___  _ __ ___       _   _  __ _
 *  | '_ ` _ \ / _ \/ _` |/ __/ _ \| '__/ _ \_____| | | |/ _` |
 *  | | | | | |  __/ (_| | (_| (_) | | |  __/_____| |_| | (_| |
 *  |_| |_| |_|\___|\__,_|\___\___/|_|  \___|      \__,_|\__,_|
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author MedCore Ukraine
 * @link https://medcore.pp.ua/
 *
 *
 */

namespace App\Domain\Billing;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Service::class);
    }

    public function findAll() : array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s', 'sc.name as category_name')
            ->leftJoin(\App\Domain\Billing\ServiceCategory::class, 'sc', \Doctrine\ORM\Query\Expr\Join::WITH, 's.category_id = sc.id')
            ->orderBy('s.name', 'ASC');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function ($row) {
            // Flatten if needed since getArrayResult with scalar selects returns nested array
            if (isset($row[0])) {
                $flat = $row[0];
                $flat['category_name'] = $row['category_name'] ?? null;
                return $flat;
            }
            return $row;
        }, $results);
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s', 'sc.name as category_name')
            ->leftJoin(\App\Domain\Billing\ServiceCategory::class, 'sc', \Doctrine\ORM\Query\Expr\Join::WITH, 's.category_id = sc.id')
            ->where('s.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if ($result && isset($result[0])) {
            $flat = $result[0];
            $flat['category_name'] = $result['category_name'] ?? null;
            return $flat;
        }

        return $result ?: null;
    }

    public function save(array $data) : ?int
    {
        $service = new Service();
        $service->setName($data['name']);
        $service->setDescription($data['description'] ?? null);
        $service->setPrice((float)$data['price']);
        $service->setCategoryId(! empty($data['category_id']) ? (int)$data['category_id'] : null);
        $service->setIsActive((bool)($data['is_active'] ?? true));
        // Note: duration_minutes isn't in original save method parameters but we should map it if available
        if (isset($data['duration_minutes'])) {
            $service->setDurationMinutes((int)$data['duration_minutes']);
        }

        try {
            $this->getEntityManager()->persist($service);
            $this->getEntityManager()->flush();
            return $service->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function update(int $id, array $data) : bool
    {
        /** @var Service|null $service */
        $service = $this->find($id);
        if (! $service) {
            return false;
        }

        if (isset($data['name'])) {
            $service->setName($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $service->setDescription($data['description']);
        }
        if (isset($data['price'])) {
            $service->setPrice((float)$data['price']);
        }
        if (array_key_exists('category_id', $data)) {
            $service->setCategoryId(! empty($data['category_id']) ? (int)$data['category_id'] : null);
        }
        if (isset($data['is_active'])) {
            $service->setIsActive((bool)$data['is_active']);
        }
        if (array_key_exists('duration_minutes', $data)) {
            $service->setDurationMinutes(! empty($data['duration_minutes']) ? (int)$data['duration_minutes'] : null);
        }

        try {
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function delete(int $id) : bool
    {
        /** @var Service|null $service */
        $service = $this->find($id);
        if (! $service) {
            return false;
        }

        try {
            $this->getEntityManager()->remove($service);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
