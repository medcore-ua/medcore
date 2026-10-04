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

namespace App\Domain\Admin;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BackupPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackupPolicy::class);
    }

    public function findAll() : array
    {
        $qb = $this->createQueryBuilder('b')
            ->orderBy('b.name', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function save(array $data) : ?int
    {
        $policy = new BackupPolicy();
        $policy->setName($data['name']);
        $policy->setDescription($data['description'] ?? null);
        $policy->setFrequency($data['frequency'] ?? 'daily');
        $policy->setRetentionDays((int)($data['retention_days'] ?? 30));
        $policy->setStatus($data['status'] ?? 'inactive');

        try {
            $this->getEntityManager()->persist($policy);
            $this->getEntityManager()->flush();
            return $policy->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function update(int $id, array $data) : bool
    {
        /** @var BackupPolicy|null $policy */
        $policy = $this->find($id);
        if (! $policy) {
            return false;
        }

        if (isset($data['name'])) {
            $policy->setName($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $policy->setDescription($data['description']);
        }
        if (isset($data['frequency'])) {
            $policy->setFrequency($data['frequency']);
        }
        if (isset($data['retention_days'])) {
            $policy->setRetentionDays((int)$data['retention_days']);
        }
        if (isset($data['status'])) {
            $policy->setStatus($data['status']);
        }
        if (isset($data['last_run_at'])) {
            try {
                $policy->setLastRunAt(new \DateTime($data['last_run_at']));
            } catch (\Exception $e) {
                // ignore invalid dates
            }
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
        /** @var BackupPolicy|null $policy */
        $policy = $this->find($id);
        if (! $policy) {
            return false;
        }

        try {
            $this->getEntityManager()->remove($policy);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
