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

class ServiceBundleRepository extends ServiceEntityRepository
{
    private BundleServiceRepository $bundleServiceRepository;

    public function __construct(ManagerRegistry $registry, BundleServiceRepository $bundleServiceRepository)
    {
        parent::__construct($registry, ServiceBundle::class);
        $this->bundleServiceRepository = $bundleServiceRepository;
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

        $bundle = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if ($bundle) {
            $bundle['services'] = $this->bundleServiceRepository->getServicesInBundle($id);
        }
        return $bundle ?: null;
    }

    public function save(array $data) : ?int
    {
        $em = $this->getEntityManager();
        $em->beginTransaction();

        try {
            $bundle = new ServiceBundle();
            $bundle->setName($data['name']);
            $bundle->setDescription($data['description'] ?? null);
            $bundle->setPrice((float)$data['price']);
            $bundle->setIsActive((bool)($data['is_active'] ?? true));

            $em->persist($bundle);
            $em->flush();

            $bundleId = $bundle->getId();

            if (! empty($data['services']) && is_array($data['services'])) {
                $this->bundleServiceRepository->syncServices($bundleId, $data['services']);
            }

            $em->commit();
            return $bundleId;
        } catch (\Exception $e) {
            $em->rollBack();
            return null;
        }
    }

    public function update(int $id, array $data) : bool
    {
        $em = $this->getEntityManager();
        $em->beginTransaction();

        try {
            /** @var ServiceBundle|null $bundle */
            $bundle = $this->find($id);
            if (! $bundle) {
                $em->rollBack();
                return false;
            }

            if (isset($data['name'])) {
                $bundle->setName($data['name']);
            }
            if (array_key_exists('description', $data)) {
                $bundle->setDescription($data['description']);
            }
            if (isset($data['price'])) {
                $bundle->setPrice((float)$data['price']);
            }
            if (isset($data['is_active'])) {
                $bundle->setIsActive((bool)$data['is_active']);
            }

            $em->flush();

            if (isset($data['services']) && is_array($data['services'])) {
                $this->bundleServiceRepository->syncServices($id, $data['services']);
            }

            $em->commit();
            return true;
        } catch (\Exception $e) {
            $em->rollBack();
            return false;
        }
    }
}
