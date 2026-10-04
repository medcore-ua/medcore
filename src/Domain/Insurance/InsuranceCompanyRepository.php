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

declare(strict_types=1);

namespace App\Domain\Insurance;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InsuranceCompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InsuranceCompany::class);
    }

    public function findById(int $id) : ?array
    {
        return $this->createQueryBuilder('ic')
            ->where('ic.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function findAll() : array
    {
        return $this->createQueryBuilder('ic')
            ->getQuery()
            ->getArrayResult();
    }

    public function create(string $name, ?string $contactPerson = null, ?string $phone = null, ?string $email = null, ?string $notes = null) : int
    {
        $company = new InsuranceCompany();
        $company->setName($name);
        $company->setContactPerson($contactPerson);
        $company->setPhone($phone);
        $company->setEmail($email);
        $company->setNotes($notes);
        $company->setCreatedAt(new \DateTime());
        $company->setUpdatedAt(new \DateTime());

        $this->getEntityManager()->persist($company);
        $this->getEntityManager()->flush();

        return $company->getId();
    }

    public function update(int $id, string $name, ?string $contactPerson = null, ?string $phone = null, ?string $email = null, ?string $notes = null) : bool
    {
        $company = $this->find($id);
        if (! $company) {
            return false;
        }

        $company->setName($name);
        $company->setContactPerson($contactPerson);
        $company->setPhone($phone);
        $company->setEmail($email);
        $company->setNotes($notes);
        $company->setUpdatedAt(new \DateTime());

        $this->getEntityManager()->flush();

        return true;
    }

    public function delete(int $id) : bool
    {
        $company = $this->find($id);
        if (! $company) {
            return false;
        }

        $this->getEntityManager()->remove($company);
        $this->getEntityManager()->flush();

        return true;
    }
}
