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

namespace App\Domain\Department;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    public function findAll() : array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.id', 'd.name', 'd.description', 'd.is_active', 'd.sort_order', 'IDENTITY(d.parent) as parent_id', 'dp.name as parent_name')
            ->leftJoin('d.parent', 'dp')
            ->orderBy('d.sort_order', 'ASC')
            ->addOrderBy('d.name', 'ASC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function findAllActive() : array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.id', 'd.name', 'd.description', 'd.is_active', 'd.sort_order', 'IDENTITY(d.parent) as parent_id', 'dp.name as parent_name')
            ->leftJoin('d.parent', 'dp')
            ->where('d.is_active = 1')
            ->orderBy('d.sort_order', 'ASC')
            ->addOrderBy('d.name', 'ASC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.id', 'd.name', 'd.description', 'd.is_active', 'd.sort_order', 'IDENTITY(d.parent) as parent_id', 'dp.name as parent_name')
            ->leftJoin('d.parent', 'dp')
            ->where('d.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
        return $result ?: null;
    }

    public function save(array $data) : bool
    {
        $department = new Department();
        $department->setName($data['name']);

        if (array_key_exists('description', $data)) {
            $department->setDescription($data['description']);
        }

        if (! empty($data['parent_id'])) {
            $parent = $this->getEntityManager()->getReference(Department::class, $data['parent_id']);
            $department->setParent($parent);
        }

        if (array_key_exists('is_active', $data)) {
            $department->setIsActive((bool)$data['is_active']);
        }

        if (array_key_exists('sort_order', $data)) {
            $department->setSortOrder((int)$data['sort_order']);
        }

        try {
            $this->getEntityManager()->persist($department);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function update(int $id, array $data) : bool
    {
        /** @var Department|null $department */
        $department = $this->find($id);
        if (! $department) {
            return false;
        }

        if (isset($data['name'])) {
            $department->setName($data['name']);
        }

        if (array_key_exists('description', $data)) {
            $department->setDescription($data['description']);
        }

        if (array_key_exists('parent_id', $data)) {
            if (! empty($data['parent_id'])) {
                $parent = $this->getEntityManager()->getReference(Department::class, $data['parent_id']);
                $department->setParent($parent);
            } else {
                $department->setParent(null);
            }
        }

        if (array_key_exists('is_active', $data)) {
            $department->setIsActive((bool)$data['is_active']);
        }

        if (array_key_exists('sort_order', $data)) {
            $department->setSortOrder((int)$data['sort_order']);
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
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.parent = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();

        if ($count > 0) {
            return false;
        }

        $department = $this->find($id);
        if (! $department) {
            return false;
        }

        try {
            $this->getEntityManager()->remove($department);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function findByName(string $name) : ?array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.id', 'd.name', 'd.description', 'd.is_active', 'd.sort_order', 'IDENTITY(d.parent) as parent_id', 'dp.name as parent_name')
            ->leftJoin('d.parent', 'dp')
            ->where('d.name = :name')
            ->setParameter('name', $name);

        return $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
    }

    public function getHierarchy() : array
    {
        $departments = $this->findAllActive();

        $hierarchy = [];
        $parentMap = [];

        foreach ($departments as $department) {
            $parentId = $department['parent_id'];
            if (null === $parentId) {
                $hierarchy[] = $department;
            } else {
                $parentMap[$parentId][] = $department;
            }
        }

        foreach ($hierarchy as &$parent) {
            $this->addChildren($parent, $parentMap);
        }

        return $hierarchy;
    }

    private function addChildren(array &$parent, array $parentMap) : void
    {
        $parentId = $parent['id'];
        if (isset($parentMap[$parentId])) {
            $parent['children'] = $parentMap[$parentId];
            foreach ($parent['children'] as &$child) {
                $this->addChildren($child, $parentMap);
            }
        }
    }
}
