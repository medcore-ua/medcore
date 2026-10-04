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

use App\Event\EntityChangedEvent;
use App\Event\PatientNotificationEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class LabOrderRepository extends ServiceEntityRepository
{
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(ManagerRegistry $registry, EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($registry, LabOrder::class);
        $this->eventDispatcher = $eventDispatcher;
    }

    public function findByMedicalRecordId(int $medicalRecordId) : array
    {
        return $this->createQueryBuilder('lo')
            ->where('lo.medical_record_id = :medical_record_id')
            ->setParameter('medical_record_id', $medicalRecordId)
            ->orderBy('lo.created_at', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    public function save(array $data) : int|false
    {
        $em = $this->getEntityManager();
        try {
            $labOrder = new LabOrder();
            $labOrder->setPatientId($data['patient_id']);
            $labOrder->setDoctorId($data['doctor_id']);
            $labOrder->setMedicalRecordId($data['medical_record_id']);
            $labOrder->setOrderCode($data['order_code']);
            if (isset($data['results'])) {
                $labOrder->setResults($data['results']);
            }
            $labOrder->setStatus($data['status'] ?? 'ordered');

            $em->persist($labOrder);
            $em->flush();

            $labOrderId = $labOrder->getId();

            $this->eventDispatcher->dispatch(new EntityChangedEvent('lab_order', $labOrderId, 'create', null, $data));
            $this->eventDispatcher->dispatch(new PatientNotificationEvent(
                $data['patient_id'],
                'lab_order_created',
                'Створено замовлення на лабораторні дослідження',
                ['lab_order_id' => $labOrderId]
            ));
            return $labOrderId;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('lo');
        $qb->select(
            'lo.id',
            'lo.patient_id',
            'lo.doctor_id',
            'lo.medical_record_id',
            'lo.order_code',
            'lo.status',
            'lo.results',
            'lo.notes',
            'lo.qr_code_hash',
            'lo.created_at',
            'lo.updated_at',
            'CONCAT(p.last_name, CONCAT(\' \', p.first_name)) AS patient_name',
            'CONCAT(u.last_name, CONCAT(\' \', u.first_name)) AS doctor_name'
        )
           ->join(\App\Domain\Patient\Patient::class, 'p', 'WITH', 'lo.patient_id = p.id')
           ->join(\App\Domain\User\User::class, 'u', 'WITH', 'lo.doctor_id = u.id')
           ->where('lo.id = :id')
           ->setParameter('id', $id);

        $result = $qb->getQuery()->getArrayResult();
        return $result ? $result[0] : null;
    }

    public function update(int $id, array $data) : bool
    {
        $em = $this->getEntityManager();
        try {
            $labOrder = $this->find($id);
            if (! $labOrder) {
                return false;
            }

            $oldLabOrder = $this->findById($id);

            $labOrder->setOrderCode($data['order_code']);
            $labOrder->setStatus($data['status']);
            if (array_key_exists('results', $data)) {
                $labOrder->setResults($data['results']);
            }
            if (array_key_exists('notes', $data)) {
                $labOrder->setNotes($data['notes']);
            }

            $em->flush();

            $this->eventDispatcher->dispatch(new EntityChangedEvent('lab_order', $id, 'update', $oldLabOrder, $data));

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function updateQrCodeHash(int $id, string $qrCodeHash) : bool
    {
        $em = $this->getEntityManager();
        try {
            $labOrder = $this->find($id);
            if (! $labOrder) {
                return false;
            }

            $labOrder->setQrCodeHash($qrCodeHash);
            $em->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function countByStatus(array $statuses) : int
    {
        if (empty($statuses)) {
            return 0;
        }

        return (int) $this->createQueryBuilder('lo')
            ->select('COUNT(lo.id)')
            ->where('lo.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
