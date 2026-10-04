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

class PatientInsurancePolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PatientInsurancePolicy::class);
    }

    public function findById(int $id) : ?array
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function findByPatientId(int $patientId) : array
    {
        return $this->createQueryBuilder('p')
            ->where('p.patient_id = :patient_id')
            ->setParameter('patient_id', $patientId)
            ->orderBy('p.is_active', 'DESC')
            ->addOrderBy('p.valid_to', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    public function create(int $patientId, int $insuranceCompanyId, string $policyNumber, ?string $groupNumber, string $validFrom, ?string $validTo, bool $isActive) : int
    {
        $policy = new PatientInsurancePolicy();
        $policy->setPatientId($patientId);
        $policy->setInsuranceCompanyId($insuranceCompanyId);
        $policy->setPolicyNumber($policyNumber);
        $policy->setGroupNumber($groupNumber);
        $policy->setValidFrom(new \DateTime($validFrom));
        $policy->setValidTo($validTo ? new \DateTime($validTo) : null);
        $policy->setIsActive($isActive);
        $policy->setCreatedAt(new \DateTime());
        $policy->setUpdatedAt(new \DateTime());

        $this->getEntityManager()->persist($policy);
        $this->getEntityManager()->flush();

        return $policy->getId();
    }

    public function update(int $id, int $patientId, int $insuranceCompanyId, string $policyNumber, ?string $groupNumber, string $validFrom, ?string $validTo, bool $isActive) : bool
    {
        $policy = $this->find($id);
        if (! $policy) {
            return false;
        }

        $policy->setPatientId($patientId);
        $policy->setInsuranceCompanyId($insuranceCompanyId);
        $policy->setPolicyNumber($policyNumber);
        $policy->setGroupNumber($groupNumber);
        $policy->setValidFrom(new \DateTime($validFrom));
        $policy->setValidTo($validTo ? new \DateTime($validTo) : null);
        $policy->setIsActive($isActive);
        $policy->setUpdatedAt(new \DateTime());

        $this->getEntityManager()->flush();

        return true;
    }

    public function delete(int $id) : bool
    {
        $policy = $this->find($id);
        if (! $policy) {
            return false;
        }

        $this->getEntityManager()->remove($policy);
        $this->getEntityManager()->flush();

        return true;
    }
}
