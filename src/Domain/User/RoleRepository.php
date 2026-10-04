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

namespace App\Domain\User;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    public function findAll() : array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.id', 'r.name')
            ->orderBy('r.name', 'ASC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.id', 'r.name')
            ->where('r.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
        return $result ?: null;
    }

    public function save(array $data) : bool
    {
        $role = new Role();
        $role->setName($data['name']);

        if (array_key_exists('description', $data)) {
            $role->setDescription($data['description']);
        }

        try {
            $this->getEntityManager()->persist($role);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function update(int $id, array $data) : bool
    {
        /** @var Role|null $role */
        $role = $this->find($id);

        if (! $role) {
            return false;
        }

        if (array_key_exists('name', $data)) {
            $role->setName($data['name']);
        }

        if (array_key_exists('description', $data)) {
            $role->setDescription($data['description']);
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
        $role = $this->find($id);

        if (! $role) {
            return false;
        }

        try {
            $this->getEntityManager()->remove($role);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
