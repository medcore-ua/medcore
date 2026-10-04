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

namespace App\Domain\Prescription;

use App\Event\EntityChangedEvent;
use App\Event\PatientNotificationEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class PrescriptionRepository extends ServiceEntityRepository
{
    private EventDispatcherInterface $eventDispatcher;
    private PrescriptionItemRepository $prescriptionItemRepository;

    public function __construct(
        ManagerRegistry $registry,
        EventDispatcherInterface $eventDispatcher,
        PrescriptionItemRepository $prescriptionItemRepository
    ) {
        parent::__construct($registry, Prescription::class);
        $this->eventDispatcher = $eventDispatcher;
        $this->prescriptionItemRepository = $prescriptionItemRepository;
    }

    public function findAll(string $searchTerm = '') : array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id', 'pat.last_name as pat_last', 'pat.first_name as pat_first', 'doc.last_name as doc_last', 'doc.first_name as doc_first', 'p.issue_date', 'p.expiry_date')
            ->join(\App\Domain\Patient\Patient::class, 'pat', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.patient_id = pat.id')
            ->join(\App\Domain\User\User::class, 'doc', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.doctor_id = doc.id');

        if (! empty($searchTerm)) {
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->like("CONCAT(pat.last_name, ' ', pat.first_name)", ':searchTerm'),
                    $qb->expr()->like("CONCAT(doc.last_name, ' ', doc.first_name)", ':searchTerm')
                )
            )->setParameter('searchTerm', '%' . $searchTerm . '%');
        }

        $qb->orderBy('p.issue_date', 'DESC');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function ($row) {
            $row['patient_name'] = trim(($row['pat_last'] ?? '') . ' ' . ($row['pat_first'] ?? ''));
            $row['doctor_name'] = trim(($row['doc_last'] ?? '') . ' ' . ($row['doc_first'] ?? ''));
            unset($row['pat_last'], $row['pat_first'], $row['doc_last'], $row['doc_first']);

            if ($row['issue_date'] instanceof \DateTimeInterface) {
                $row['issue_date'] = $row['issue_date']->format('Y-m-d H:i:s');
            }
            if ($row['expiry_date'] instanceof \DateTimeInterface) {
                $row['expiry_date'] = $row['expiry_date']->format('Y-m-d H:i:s');
            }
            return $row;
        }, $results);
    }

    public function save(array $data) : ?int
    {
        $em = $this->getEntityManager();
        $em->beginTransaction();
        try {
            $prescription = new Prescription();
            $prescription->setPatientId((int)$data['patient_id']);
            $prescription->setDoctorId((int)$data['doctor_id']);
            $prescription->setMedicalRecordId(! empty($data['medical_record_id']) ? (int)$data['medical_record_id'] : null);

            if (! empty($data['issue_date'])) {
                $prescription->setIssueDate(new \DateTime($data['issue_date']));
            }
            if (! empty($data['expiry_date'])) {
                $prescription->setExpiryDate(new \DateTime($data['expiry_date']));
            }
            $prescription->setNotes($data['notes'] ?? null);

            $em->persist($prescription);
            $em->flush();
            $prescriptionId = $prescription->getId();

            if (! empty($data['items']) && is_array($data['items'])) {
                $this->prescriptionItemRepository->saveItems($prescriptionId, $data['items']);
            }

            $em->commit();

            $this->eventDispatcher->dispatch(new EntityChangedEvent('prescription', $prescriptionId, 'create', null, $data));
            $this->eventDispatcher->dispatch(new PatientNotificationEvent(
                $data['patient_id'],
                'prescription_created',
                'Виписано новий рецепт',
                ['prescription_id' => $prescriptionId]
            ));
            return $prescriptionId;
        } catch (\Exception $e) {
            $em->rollBack();
            return null;
        }
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p', 'pat.last_name as pat_last', 'pat.first_name as pat_first', 'doc.last_name as doc_last', 'doc.first_name as doc_first')
            ->join(\App\Domain\Patient\Patient::class, 'pat', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.patient_id = pat.id')
            ->join(\App\Domain\User\User::class, 'doc', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.doctor_id = doc.id')
            ->where('p.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if ($result && isset($result[0])) {
            $flat = $result[0];
            $flat['patient_name'] = trim(($result['pat_last'] ?? '') . ' ' . ($result['pat_first'] ?? ''));
            $flat['doctor_name'] = trim(($result['doc_last'] ?? '') . ' ' . ($result['doc_first'] ?? ''));

            if ($flat['issue_date'] instanceof \DateTimeInterface) {
                $flat['issue_date'] = $flat['issue_date']->format('Y-m-d H:i:s');
            }
            if ($flat['expiry_date'] instanceof \DateTimeInterface) {
                $flat['expiry_date'] = $flat['expiry_date']->format('Y-m-d H:i:s');
            }

            $flat['items'] = $this->prescriptionItemRepository->findItemsByPrescriptionId($id);
            return $flat;
        }
        return null;
    }

    public function findByPatientId(int $patientId) : array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id', 'p.issue_date', 'p.expiry_date', 'doc.last_name as doc_last', 'doc.first_name as doc_first')
            ->join(\App\Domain\User\User::class, 'doc', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.doctor_id = doc.id')
            ->where('p.patient_id = :patient_id')
            ->setParameter('patient_id', $patientId)
            ->orderBy('p.issue_date', 'DESC');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function ($row) {
            $row['doctor_name'] = trim(($row['doc_last'] ?? '') . ' ' . ($row['doc_first'] ?? ''));
            unset($row['doc_last'], $row['doc_first']);
            if ($row['issue_date'] instanceof \DateTimeInterface) {
                $row['issue_date'] = $row['issue_date']->format('Y-m-d H:i:s');
            }
            if ($row['expiry_date'] instanceof \DateTimeInterface) {
                $row['expiry_date'] = $row['expiry_date']->format('Y-m-d H:i:s');
            }
            return $row;
        }, $results);
    }

    public function findByDoctorId(int $doctorId, string $searchTerm = '') : array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id', 'p.issue_date', 'p.expiry_date', 'pat.last_name as pat_last', 'pat.first_name as pat_first', 'doc.last_name as doc_last', 'doc.first_name as doc_first')
            ->join(\App\Domain\Patient\Patient::class, 'pat', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.patient_id = pat.id')
            ->join(\App\Domain\User\User::class, 'doc', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.doctor_id = doc.id')
            ->where('p.doctor_id = :doctor_id')
            ->setParameter('doctor_id', $doctorId);

        if (! empty($searchTerm)) {
            $qb->andWhere(
                $qb->expr()->like("CONCAT(pat.last_name, ' ', pat.first_name)", ':searchTerm')
            )->setParameter('searchTerm', '%' . $searchTerm . '%');
        }

        $qb->orderBy('p.issue_date', 'DESC');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function ($row) {
            $row['patient_name'] = trim(($row['pat_last'] ?? '') . ' ' . ($row['pat_first'] ?? ''));
            $row['doctor_name'] = trim(($row['doc_last'] ?? '') . ' ' . ($row['doc_first'] ?? ''));
            unset($row['pat_last'], $row['pat_first'], $row['doc_last'], $row['doc_first']);

            if ($row['issue_date'] instanceof \DateTimeInterface) {
                $row['issue_date'] = $row['issue_date']->format('Y-m-d H:i:s');
            }
            if ($row['expiry_date'] instanceof \DateTimeInterface) {
                $row['expiry_date'] = $row['expiry_date']->format('Y-m-d H:i:s');
            }
            return $row;
        }, $results);
    }
}
