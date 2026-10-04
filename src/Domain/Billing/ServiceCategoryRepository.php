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

class ServiceCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCategory::class);
    }

    public function findAllCategories() : array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.description')
            ->orderBy('c.name', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function save(array $data) : ?int
    {
        $category = new ServiceCategory();
        $category->setName($data['name']);
        $category->setDescription($data['description'] ?? null);

        try {
            $this->getEntityManager()->persist($category);
            $this->getEntityManager()->flush();
            return $category->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function update(int $id, array $data) : bool
    {
        /** @var ServiceCategory|null $category */
        $category = $this->find($id);
        if (! $category) {
            return false;
        }

        if (isset($data['name'])) {
            $category->setName($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $category->setDescription($data['description']);
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
        /** @var ServiceCategory|null $category */
        $category = $this->find($id);
        if (! $category) {
            return false;
        }

        try {
            $this->getEntityManager()->remove($category);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function hasServices(int $categoryId) : bool
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(\App\Domain\Billing\Service::class, 's')
            ->where('s.category_id = :category_id')
            ->setParameter('category_id', $categoryId);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
