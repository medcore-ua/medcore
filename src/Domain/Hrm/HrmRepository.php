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

namespace App\Domain\Hrm;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HrmRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Employee::class);
    }

    public function findAll() : array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e, d.name as department_name')
            ->leftJoin('App\Domain\Department\Department', 'd', 'WITH', 'e.department_id = d.id')
            ->orderBy('e.last_name', 'ASC')
            ->addOrderBy('e.first_name', 'ASC');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function ($row) {
            $flat = $row[0];
            $flat['department_name'] = $row['department_name'] ?? null;
            return $flat;
        }, $results);
    }

    public function save(array $data) : bool
    {
        $employee = new Employee();
        $employee->setFirstName($data['first_name']);
        $employee->setLastName($data['last_name']);
        $employee->setMiddleName($data['middle_name'] ?? null);
        $employee->setPosition($data['position']);
        $employee->setDepartmentId($data['department_id'] ?? null);
        $employee->setHireDate(new \DateTime($data['hire_date']));
        $employee->setSalary(! empty($data['salary']) ? (float)$data['salary'] : null);
        $employee->setContactPhone($data['contact_phone'] ?? null);
        $employee->setStatus($data['status'] ?? 'active');
        $employee->setUserId(! empty($data['user_id']) ? (int)$data['user_id'] : null);

        $this->getEntityManager()->persist($employee);
        $this->getEntityManager()->flush();

        return true;
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e, d.name as department_name')
            ->leftJoin('App\Domain\Department\Department', 'd', 'WITH', 'e.department_id = d.id')
            ->where('e.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
        if (! $result) {
            return null;
        }

        $flat = $result[0];
        $flat['department_name'] = $result['department_name'] ?? null;
        return $flat;
    }

    public function update(int $id, array $data) : bool
    {
        $employee = $this->find($id);
        if (! $employee) {
            return false;
        }

        $employee->setFirstName($data['first_name']);
        $employee->setLastName($data['last_name']);
        $employee->setMiddleName($data['middle_name'] ?? null);
        $employee->setPosition($data['position']);
        $employee->setDepartmentId($data['department_id'] ?? null);
        $employee->setHireDate(new \DateTime($data['hire_date']));
        $employee->setFireDate(! empty($data['fire_date']) ? new \DateTime($data['fire_date']) : null);
        $employee->setSalary(! empty($data['salary']) ? (float)$data['salary'] : null);
        $employee->setContactPhone($data['contact_phone'] ?? null);
        $employee->setStatus($data['status'] ?? 'active');
        $employee->setUserId(! empty($data['user_id']) ? (int)$data['user_id'] : null);

        $this->getEntityManager()->flush();

        return true;
    }

    public function updateStatus(int $id, string $status) : bool
    {
        $employee = $this->find($id);
        if (! $employee) {
            return false;
        }

        $employee->setStatus($status);
        $this->getEntityManager()->flush();

        return true;
    }

    public function findByUserId(int $userId) : ?array
    {
        return $this->createQueryBuilder('e')
            ->where('e.user_id = :user_id')
            ->setParameter('user_id', $userId)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function findByDepartment(int $departmentId) : array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e, d.name as department_name, dp.name as parent_name, u.email as user_email')
            ->leftJoin('App\Domain\Department\Department', 'd', 'WITH', 'e.department_id = d.id')
            ->leftJoin('App\Domain\Department\Department', 'dp', 'WITH', 'd.parent_id = dp.id')
            ->leftJoin('App\Domain\User\User', 'u', 'WITH', 'e.user_id = u.id')
            ->where('e.department_id = :department_id')
            ->setParameter('department_id', $departmentId)
            ->orderBy('e.last_name', 'ASC')
            ->addOrderBy('e.first_name', 'ASC');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function ($row) {
            $flat = $row[0];
            $flat['department_name'] = $row['department_name'] ?? null;
            $flat['parent_name'] = $row['parent_name'] ?? null;
            $flat['user_email'] = $row['user_email'] ?? null;
            return $flat;
        }, $results);
    }
}
