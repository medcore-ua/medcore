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

namespace App\Domain\LabOrder;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LabResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabResource::class);
    }

    public function findAll() : array
    {
        return $this->createQueryBuilder('lr')
            ->select('lr.id', 'lr.name', 'lr.type', 'lr.capacity', 'lr.is_available', 'lr.notes')
            ->orderBy('lr.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function findById(int $id) : ?array
    {
        $result = $this->createQueryBuilder('lr')
            ->select('lr.id', 'lr.name', 'lr.type', 'lr.capacity', 'lr.is_available', 'lr.notes')
            ->where('lr.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getArrayResult();
        return $result ? $result[0] : null;
    }

    public function save(array $data) : ?int
    {
        $em = $this->getEntityManager();
        try {
            $resource = new LabResource();
            $resource->setName($data['name']);
            if (isset($data['type'])) {
                $resource->setType($data['type']);
            }
            $resource->setCapacity((int)($data['capacity'] ?? 1));
            $resource->setIsAvailable((bool)($data['is_available'] ?? true));
            if (isset($data['notes'])) {
                $resource->setNotes($data['notes']);
            }

            $em->persist($resource);
            $em->flush();

            return $resource->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function update(int $id, array $data) : bool
    {
        $em = $this->getEntityManager();
        try {
            $resource = $this->find($id);
            if (! $resource) {
                return false;
            }

            $resource->setName($data['name']);
            if (array_key_exists('type', $data)) {
                $resource->setType($data['type']);
            }
            $resource->setCapacity((int)($data['capacity'] ?? 1));
            $resource->setIsAvailable((bool)($data['is_available'] ?? true));
            if (array_key_exists('notes', $data)) {
                $resource->setNotes($data['notes']);
            }

            $em->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // Check if a resource is available and has capacity for a given time slot
    public function checkResourceAvailability(
        int $resourceId,
        string $startTime,
        string $endTime,
        int $requiredCapacity = 1
    ) : bool {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $qb->select('lr.capacity - COUNT(lor.lab_order_id) as remaining_capacity')
           ->from('lab_resources', 'lr')
           ->leftJoin('lr', 'lab_order_resources', 'lor', 'lr.id = lor.lab_resource_id')
           ->leftJoin('lor', 'lab_orders', 'lo', 'lor.lab_order_id = lo.id')
           ->where('lr.id = :resource_id')
           ->andWhere('lr.is_available = TRUE')
           ->andWhere('lo.id IS NULL OR (lo.start_time NOT BETWEEN :start_time AND :end_time AND lo.end_time NOT BETWEEN :start_time AND :end_time)')
           ->groupBy('lr.id', 'lr.capacity')
           ->setParameter('resource_id', $resourceId)
           ->setParameter('start_time', $startTime)
           ->setParameter('end_time', $endTime);

        $result = $qb->fetchAssociative();
        return ($result && $result['remaining_capacity'] >= $requiredCapacity);
    }
}
