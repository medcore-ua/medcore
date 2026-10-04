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

class ClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Claim::class);
    }

    public function findById(int $id) : ?array
    {
        return $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function findByIdWithDetails(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c, pip.patient_id, p.first_name, p.last_name, CONCAT(p.first_name, \' \', p.last_name) as patient_name, ic.name as insurance_company_name')
            ->join('App\Domain\Insurance\PatientInsurancePolicy', 'pip', 'WITH', 'c.patient_policy_id = pip.id')
            ->join('App\Domain\Patient\Patient', 'p', 'WITH', 'pip.patient_id = p.id')
            ->join('App\Domain\Insurance\InsuranceCompany', 'ic', 'WITH', 'pip.insurance_company_id = ic.id')
            ->where('c.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
        if (! $result) {
            return null;
        }

        $flat = $result[0];
        $flat['patient_id'] = $result['patient_id'];
        $flat['first_name'] = $result['first_name'];
        $flat['last_name'] = $result['last_name'];
        $flat['patient_name'] = $result['patient_name'];
        $flat['insurance_company_name'] = $result['insurance_company_name'];
        return $flat;
    }

    public function findByInvoiceId(int $invoiceId) : ?array
    {
        return $this->createQueryBuilder('c')
            ->where('c.invoice_id = :invoice_id')
            ->setParameter('invoice_id', $invoiceId)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function findByPatientPolicyId(int $patientPolicyId) : array
    {
        return $this->createQueryBuilder('c')
            ->where('c.patient_policy_id = :patient_policy_id')
            ->setParameter('patient_policy_id', $patientPolicyId)
            ->getQuery()
            ->getArrayResult();
    }

    public function findAll() : array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c, pip.patient_id, p.first_name, p.last_name, CONCAT(p.first_name, \' \', p.last_name) as patient_name, ic.name as insurance_company_name')
            ->join('App\Domain\Insurance\PatientInsurancePolicy', 'pip', 'WITH', 'c.patient_policy_id = pip.id')
            ->join('App\Domain\Patient\Patient', 'p', 'WITH', 'pip.patient_id = p.id')
            ->join('App\Domain\Insurance\InsuranceCompany', 'ic', 'WITH', 'pip.insurance_company_id = ic.id')
            ->orderBy('c.created_at', 'DESC');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function ($row) {
            $flat = $row[0];
            $flat['patient_id'] = $row['patient_id'];
            $flat['first_name'] = $row['first_name'];
            $flat['last_name'] = $row['last_name'];
            $flat['patient_name'] = $row['patient_name'];
            $flat['insurance_company_name'] = $row['insurance_company_name'];
            return $flat;
        }, $results);
    }

    public function create(int $invoiceId, int $patientPolicyId, string $status, float $totalClaimed, ?string $submittedAt = null) : int
    {
        $claim = new Claim();
        $claim->setInvoiceId($invoiceId);
        $claim->setPatientPolicyId($patientPolicyId);
        $claim->setStatus($status);
        $claim->setTotalClaimed($totalClaimed);
        $claim->setSubmittedAt($submittedAt ? new \DateTime($submittedAt) : null);
        $claim->setCreatedAt(new \DateTime());
        $claim->setUpdatedAt(new \DateTime());

        $this->getEntityManager()->persist($claim);
        $this->getEntityManager()->flush();

        return $claim->getId();
    }

    public function update(int $id, string $status, ?string $submittedAt = null, ?float $totalPaid = null) : bool
    {
        $claim = $this->find($id);
        if (! $claim) {
            return false;
        }

        $claim->setStatus($status);
        $claim->setSubmittedAt($submittedAt ? new \DateTime($submittedAt) : null);
        $claim->setTotalPaid($totalPaid);
        $claim->setUpdatedAt(new \DateTime());

        $this->getEntityManager()->flush();

        return true;
    }
}
