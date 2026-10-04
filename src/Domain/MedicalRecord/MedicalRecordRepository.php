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

namespace App\Domain\MedicalRecord;

use App\Event\EntityChangedEvent;
use App\Event\PatientNotificationEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class MedicalRecordRepository extends ServiceEntityRepository
{
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(ManagerRegistry $registry, EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($registry, MedicalRecord::class);
        $this->eventDispatcher = $eventDispatcher;
    }

    public function findByPatientId(int $patientId) : array
    {
        $qb = $this->createQueryBuilder('mr')
            ->select(
                'mr.id',
                'IDENTITY(mr.patient) as patient_id',
                'IDENTITY(mr.appointment) as appointment_id',
                'IDENTITY(mr.doctor) as doctor_id',
                'mr.visit_date',
                'mr.diagnosis_code',
                'mr.diagnosis_text',
                'mr.treatment',
                'mr.notes',
                "CONCAT(u.last_name, ' ', u.first_name) as doctor_name",
                "CONCAT(p.last_name, ' ', p.first_name) as patient_name"
            )
            ->join('mr.doctor', 'u')
            ->join('mr.patient', 'p')
            ->where('mr.patient = :patient_id')
            ->setParameter('patient_id', $patientId)
            ->orderBy('mr.visit_date', 'DESC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function findAll(string $searchTerm = '') : array
    {
        $qb = $this->createQueryBuilder('mr')
            ->select(
                'mr.id',
                'IDENTITY(mr.patient) as patient_id',
                'IDENTITY(mr.appointment) as appointment_id',
                'IDENTITY(mr.doctor) as doctor_id',
                'mr.visit_date',
                'mr.diagnosis_code',
                'mr.diagnosis_text',
                'mr.treatment',
                'mr.notes',
                "CONCAT(u.last_name, ' ', u.first_name) as doctor_name",
                "CONCAT(p.last_name, ' ', p.first_name) as patient_name"
            )
            ->join('mr.doctor', 'u')
            ->join('mr.patient', 'p');

        if (! empty($searchTerm)) {
            $qb->where("CONCAT(p.last_name, ' ', p.first_name) LIKE :searchTerm")
               ->orWhere("CONCAT(u.last_name, ' ', u.first_name) LIKE :searchTerm")
               ->orWhere('mr.diagnosis_code LIKE :searchTerm')
               ->setParameter('searchTerm', '%' . $searchTerm . '%');
        }

        $qb->orderBy('mr.visit_date', 'DESC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function findByDoctorId(int $doctorId, string $searchTerm = '') : array
    {
        $qb = $this->createQueryBuilder('mr')
            ->select(
                'mr.id',
                'IDENTITY(mr.patient) as patient_id',
                'IDENTITY(mr.appointment) as appointment_id',
                'IDENTITY(mr.doctor) as doctor_id',
                'mr.visit_date',
                'mr.diagnosis_code',
                'mr.diagnosis_text',
                'mr.treatment',
                'mr.notes',
                "CONCAT(u.last_name, ' ', u.first_name) as doctor_name",
                "CONCAT(p.last_name, ' ', p.first_name) as patient_name"
            )
            ->join('mr.doctor', 'u')
            ->join('mr.patient', 'p')
            ->where('mr.doctor = :doctor_id')
            ->setParameter('doctor_id', $doctorId);

        if (! empty($searchTerm)) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like("CONCAT(p.last_name, ' ', p.first_name)", ':searchTerm'),
                    $qb->expr()->like('mr.diagnosis_code', ':searchTerm')
                )
            )->setParameter('searchTerm', '%' . $searchTerm . '%');
        }

        $qb->orderBy('mr.visit_date', 'DESC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function save(array $data) : int|false
    {
        $mr = new MedicalRecord();

        $patient = $this->getEntityManager()->getReference(\App\Domain\Patient\Patient::class, $data['patient_id']);
        $mr->setPatient($patient);

        $doctor = $this->getEntityManager()->getReference(\App\Domain\User\User::class, $data['doctor_id']);
        $mr->setDoctor($doctor);

        if (! empty($data['appointment_id'])) {
            $appointment = $this->getEntityManager()->getReference(\App\Domain\Appointment\Appointment::class, $data['appointment_id']);
            $mr->setAppointment($appointment);
        }

        if (! empty($data['visit_date'])) {
            try {
                $mr->setVisitDate(new \DateTime($data['visit_date']));
            } catch (\Exception $e) {
            }
        }

        if (array_key_exists('diagnosis_code', $data)) {
            $mr->setDiagnosisCode($data['diagnosis_code']);
        }
        if (array_key_exists('diagnosis_text', $data)) {
            $mr->setDiagnosisText($data['diagnosis_text']);
        }
        if (array_key_exists('treatment', $data)) {
            $mr->setTreatment($data['treatment']);
        }
        if (array_key_exists('notes', $data)) {
            $mr->setNotes($data['notes']);
        }

        try {
            $this->getEntityManager()->persist($mr);
            $this->getEntityManager()->flush();

            $medicalRecordId = $mr->getId();

            if (isset($data['icd_codes']) && is_array($data['icd_codes'])) {
                $this->attachIcdCodes($medicalRecordId, $data['icd_codes']);
            }
            if (isset($data['intervention_codes']) && is_array($data['intervention_codes'])) {
                $this->attachInterventionCodes($medicalRecordId, $data['intervention_codes']);
            }

            $this->eventDispatcher->dispatch(new EntityChangedEvent('medical_record', $medicalRecordId, 'create', null, $data));
            $this->eventDispatcher->dispatch(new PatientNotificationEvent(
                $data['patient_id'],
                'medical_record_created',
                'Створено новий медичний запис після візиту',
                ['medical_record_id' => $medicalRecordId]
            ));

            return $medicalRecordId;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function update(int $id, array $data) : bool
    {
        $oldMedicalRecord = $this->findById($id);
        if (! $oldMedicalRecord) {
            return false;
        }

        /** @var MedicalRecord|null $mr */
        $mr = $this->find($id);
        if (! $mr) {
            return false;
        }

        if (isset($data['patient_id'])) {
            $patient = $this->getEntityManager()->getReference(\App\Domain\Patient\Patient::class, $data['patient_id']);
            $mr->setPatient($patient);
        }

        if (isset($data['doctor_id'])) {
            $doctor = $this->getEntityManager()->getReference(\App\Domain\User\User::class, $data['doctor_id']);
            $mr->setDoctor($doctor);
        }

        if (array_key_exists('appointment_id', $data)) {
            if (! empty($data['appointment_id'])) {
                $appointment = $this->getEntityManager()->getReference(\App\Domain\Appointment\Appointment::class, $data['appointment_id']);
                $mr->setAppointment($appointment);
            } else {
                $mr->setAppointment(null);
            }
        }

        if (! empty($data['visit_date'])) {
            try {
                $mr->setVisitDate(new \DateTime($data['visit_date']));
            } catch (\Exception $e) {
            }
        }

        if (array_key_exists('diagnosis_code', $data)) {
            $mr->setDiagnosisCode($data['diagnosis_code']);
        }
        if (array_key_exists('diagnosis_text', $data)) {
            $mr->setDiagnosisText($data['diagnosis_text']);
        }
        if (array_key_exists('treatment', $data)) {
            $mr->setTreatment($data['treatment']);
        }
        if (array_key_exists('notes', $data)) {
            $mr->setNotes($data['notes']);
        }

        try {
            $this->getEntityManager()->flush();

            if (isset($data['icd_codes']) && is_array($data['icd_codes'])) {
                $this->attachIcdCodes($id, $data['icd_codes']);
            }
            if (isset($data['intervention_codes']) && is_array($data['intervention_codes'])) {
                $this->attachInterventionCodes($id, $data['intervention_codes']);
            }
            $this->eventDispatcher->dispatch(new EntityChangedEvent('medical_record', $id, 'update', $oldMedicalRecord, $data));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('mr')
            ->select(
                'mr.id',
                'IDENTITY(mr.patient) as patient_id',
                'IDENTITY(mr.appointment) as appointment_id',
                'IDENTITY(mr.doctor) as doctor_id',
                'mr.visit_date',
                'mr.diagnosis_code',
                'mr.diagnosis_text',
                'mr.treatment',
                'mr.notes',
                "CONCAT(u.last_name, ' ', u.first_name) as doctor_name",
                "CONCAT(p.last_name, ' ', p.first_name) as patient_name"
            )
            ->join('mr.doctor', 'u')
            ->join('mr.patient', 'p')
            ->where('mr.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if ($result) {
            $result['icd_codes'] = $this->getIcdCodesForMedicalRecord($id);
            $result['intervention_codes'] = $this->getInterventionCodesForMedicalRecord($id);
        }
        return $result;
    }

    public function attachIcdCodes(int $medicalRecordId, array $icdCodeIds) : bool
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $qb->delete('medical_record_icd')
           ->where('medical_record_id = :medical_record_id')
           ->setParameter('medical_record_id', $medicalRecordId)
           ->executeStatement();

        $icdCodeIds = array_filter($icdCodeIds, fn ($id) => is_numeric($id) && $id > 0);

        if (empty($icdCodeIds)) {
            return true;
        }

        $inserted = 0;
        foreach ($icdCodeIds as $icdCodeId) {
            $insertQb = $this->getEntityManager()->getConnection()->createQueryBuilder();
            $inserted += $insertQb->insert('medical_record_icd')
                ->setValue('medical_record_id', ':medical_record_id')
                ->setValue('icd_code_id', ':icd_code_id')
                ->setParameter('medical_record_id', $medicalRecordId)
                ->setParameter('icd_code_id', $icdCodeId)
                ->executeStatement();
        }

        return $inserted > 0;
    }

    public function getIcdCodesForMedicalRecord(int $medicalRecordId) : array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $qb->select('ic.id', 'ic.code', 'ic.description')
           ->from('medical_record_icd', 'mri')
           ->join('mri', 'icd_codes', 'ic', 'mri.icd_code_id = ic.id')
           ->where('mri.medical_record_id = :medical_record_id')
           ->setParameter('medical_record_id', $medicalRecordId);

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function attachInterventionCodes(int $medicalRecordId, array $interventionCodeIds) : bool
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $qb->delete('medical_record_intervention')
           ->where('medical_record_id = :medical_record_id')
           ->setParameter('medical_record_id', $medicalRecordId)
           ->executeStatement();

        $interventionCodeIds = array_filter($interventionCodeIds, fn ($id) => is_numeric($id) && $id > 0);

        if (empty($interventionCodeIds)) {
            return true;
        }

        $inserted = 0;
        foreach ($interventionCodeIds as $interventionCodeId) {
            $insertQb = $this->getEntityManager()->getConnection()->createQueryBuilder();
            $inserted += $insertQb->insert('medical_record_intervention')
                ->setValue('medical_record_id', ':medical_record_id')
                ->setValue('intervention_code_id', ':intervention_code_id')
                ->setParameter('medical_record_id', $medicalRecordId)
                ->setParameter('intervention_code_id', $interventionCodeId)
                ->executeStatement();
        }

        return $inserted > 0;
    }

    public function getInterventionCodesForMedicalRecord(int $medicalRecordId) : array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $qb->select('ic.id', 'ic.code', 'ic.description')
           ->from('medical_record_intervention', 'mri')
           ->join('mri', 'intervention_codes', 'ic', 'mri.intervention_code_id = ic.id')
           ->where('mri.medical_record_id = :medical_record_id')
           ->setParameter('medical_record_id', $medicalRecordId);

        return $qb->executeQuery()->fetchAllAssociative();
    }
}
