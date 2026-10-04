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

namespace App\Domain\Appointment;

use App\Event\EntityChangedEvent;
use App\Event\PatientNotificationEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class AppointmentRepository extends ServiceEntityRepository
{
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(ManagerRegistry $registry, EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($registry, Appointment::class);
        $this->eventDispatcher = $eventDispatcher;
    }

    public function findAll() : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.id', 'a.start_time', 'a.end_time', 'a.status', 'IDENTITY(a.doctor) as doctor_id', 'a.room_id')
            ->addSelect("CONCAT(p.last_name, ' ', p.first_name) as patient_name")
            ->addSelect("CONCAT(u.last_name, ' ', u.first_name) as doctor_name")
            ->addSelect("r.name as room_name")
            ->join('a.patient', 'p')
            ->join('a.doctor', 'u')
            ->leftJoin(\App\Domain\Room\Room::class, 'r', 'WITH', 'a.room_id = r.id')
            ->orderBy('a.start_time', 'DESC');

        $results = $qb->getQuery()->getArrayResult();

        foreach ($results as &$row) {
            if (isset($row['start_time']) && $row['start_time'] instanceof \DateTimeInterface) {
                $row['start_time'] = $row['start_time']->format('Y-m-d H:i:s');
            }
            if (isset($row['end_time']) && $row['end_time'] instanceof \DateTimeInterface) {
                $row['end_time'] = $row['end_time']->format('Y-m-d H:i:s');
            }
        }

        return $results;
    }

    public function save(array $data) : int|false
    {
        $appointment = new Appointment();

        $patient = $this->getEntityManager()->getReference(\App\Domain\Patient\Patient::class, $data['patient_id']);
        $appointment->setPatient($patient);

        $doctor = $this->getEntityManager()->getReference(\App\Domain\User\User::class, $data['doctor_id']);
        $appointment->setDoctor($doctor);

        try {
            $appointment->setStartTime(new \DateTime($data['start_time']));
            $appointment->setEndTime(new \DateTime($data['end_time']));
        } catch (\Exception $e) {
            return false;
        }

        $appointment->setStatus($data['status'] ?? 'scheduled');

        if (array_key_exists('notes', $data)) {
            $appointment->setNotes($data['notes']);
        }

        if (! empty($data['waitlist_id'])) {
            $appointment->setWaitlistId($data['waitlist_id']);
        }

        if (! empty($data['room_id'])) {
            $appointment->setRoomId($data['room_id']);
        }

        try {
            $this->getEntityManager()->persist($appointment);
            $this->getEntityManager()->flush();

            $appointmentId = $appointment->getId();

            $this->eventDispatcher->dispatch(new EntityChangedEvent('appointment', $appointmentId, 'create', null, $data));
            $this->eventDispatcher->dispatch(new PatientNotificationEvent(
                $data['patient_id'],
                'appointment_scheduled',
                'Ваш прийом заплановано на ' . $data['start_time'],
                ['appointment_id' => $appointmentId]
            ));

            return $appointmentId;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'a.id',
                'a.start_time',
                'a.end_time',
                'a.status',
                'a.notes',
                'a.waitlist_id',
                'a.room_id',
                'a.ehealth_episode_id',
                'a.created_at',
                'a.updated_at',
                'IDENTITY(a.patient) as patient_id',
                'IDENTITY(a.doctor) as doctor_id'
            )
            ->addSelect("CONCAT(p.last_name, ' ', p.first_name) as patient_name")
            ->addSelect("CONCAT(u.last_name, ' ', u.first_name) as doctor_name")
            ->addSelect("r.name as room_name")
            ->join('a.patient', 'p')
            ->join('a.doctor', 'u')
            ->leftJoin(\App\Domain\Room\Room::class, 'r', 'WITH', 'a.room_id = r.id')
            ->where('a.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if ($result) {
            foreach (['start_time', 'end_time', 'created_at', 'updated_at'] as $field) {
                if (isset($result[$field]) && $result[$field] instanceof \DateTimeInterface) {
                    $result[$field] = $result[$field]->format('Y-m-d H:i:s');
                }
            }
        }

        return $result;
    }

    public function update(int $id, array $data) : bool
    {
        $oldAppointment = $this->findById($id);
        if (! $oldAppointment) {
            return false;
        }

        /** @var Appointment|null $appointment */
        $appointment = $this->find($id);
        if (! $appointment) {
            return false;
        }

        if (isset($data['patient_id'])) {
            $patient = $this->getEntityManager()->getReference(\App\Domain\Patient\Patient::class, $data['patient_id']);
            $appointment->setPatient($patient);
        }
        if (isset($data['doctor_id'])) {
            $doctor = $this->getEntityManager()->getReference(\App\Domain\User\User::class, $data['doctor_id']);
            $appointment->setDoctor($doctor);
        }

        if (isset($data['start_time'])) {
            try {
                $appointment->setStartTime(new \DateTime($data['start_time']));
            } catch (\Exception $e) {
            }
        }
        if (isset($data['end_time'])) {
            try {
                $appointment->setEndTime(new \DateTime($data['end_time']));
            } catch (\Exception $e) {
            }
        }

        if (isset($data['status'])) {
            $appointment->setStatus($data['status']);
        }
        if (array_key_exists('notes', $data)) {
            $appointment->setNotes($data['notes']);
        }
        if (array_key_exists('room_id', $data)) {
            $appointment->setRoomId(empty($data['room_id']) ? null : $data['room_id']);
        }

        try {
            $this->getEntityManager()->flush();
            $this->eventDispatcher->dispatch(new EntityChangedEvent('appointment', $id, 'update', $oldAppointment, $data));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function updateStatus(int $id, string $status) : bool
    {
        $oldAppointment = $this->findById($id);
        if (! $oldAppointment) {
            return false;
        }

        /** @var Appointment|null $appointment */
        $appointment = $this->find($id);
        if (! $appointment) {
            return false;
        }

        $appointment->setStatus($status);

        try {
            $this->getEntityManager()->flush();
            if ($oldAppointment['status'] !== $status) {
                $this->eventDispatcher->dispatch(new EntityChangedEvent('appointment', $id, 'update', $oldAppointment, ['status' => $status]));
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function findByPatientId(int $patientId) : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'a.id',
                'a.start_time',
                'a.end_time',
                'a.status',
                'a.notes',
                'a.waitlist_id',
                'a.room_id',
                'a.ehealth_episode_id',
                'a.created_at',
                'a.updated_at',
                'IDENTITY(a.patient) as patient_id',
                'IDENTITY(a.doctor) as doctor_id'
            )
            ->addSelect("CONCAT(p.last_name, ' ', p.first_name) as patient_name")
            ->addSelect("CONCAT(u.last_name, ' ', u.first_name) as doctor_name")
            ->join('a.patient', 'p')
            ->leftJoin('a.doctor', 'u')
            ->where('IDENTITY(a.patient) = :patient_id')
            ->setParameter('patient_id', $patientId)
            ->orderBy('a.start_time', 'DESC');

        return $this->formatDatesInResults($qb->getQuery()->getArrayResult());
    }

    public function findByDateRange(string $start, string $end) : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'a.id',
                'a.start_time',
                'a.end_time',
                'a.status',
                'a.waitlist_id',
                'a.room_id',
                'IDENTITY(a.doctor) as doctor_id'
            )
            ->addSelect("CONCAT(p.last_name, ' ', p.first_name) as patient_name")
            ->addSelect("CONCAT(u.last_name, ' ', u.first_name) as doctor_name")
            ->addSelect("r.name as room_name")
            ->join('a.patient', 'p')
            ->join('a.doctor', 'u')
            ->leftJoin(\App\Domain\Room\Room::class, 'r', 'WITH', 'a.room_id = r.id')
            ->where('a.start_time >= :start_time AND a.end_time <= :end_time')
            ->setParameter('start_time', $start)
            ->setParameter('end_time', $end)
            ->orderBy('a.start_time', 'ASC');

        return $this->formatDatesInResults($qb->getQuery()->getArrayResult());
    }

    public function isPatientAssignedToDoctor(int $patientId, int $doctorId) : bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('1')
            ->where('IDENTITY(a.patient) = :patient_id')
            ->andWhere('IDENTITY(a.doctor) = :doctor_id')
            ->setParameter('patient_id', $patientId)
            ->setParameter('doctor_id', $doctorId)
            ->setMaxResults(1);

        return (bool)$qb->getQuery()->getOneOrNullResult();
    }

    public function isAppointmentOwnedByDoctor(int $appointmentId, int $doctorId) : bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('1')
            ->where('a.id = :appointment_id')
            ->andWhere('IDENTITY(a.doctor) = :doctor_id')
            ->setParameter('appointment_id', $appointmentId)
            ->setParameter('doctor_id', $doctorId)
            ->setMaxResults(1);

        return (bool)$qb->getQuery()->getOneOrNullResult();
    }

    public function findAppointmentsForReminder(int $minutesBefore) : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'a.id',
                'a.start_time',
                'a.end_time',
                'IDENTITY(a.patient) as patient_id',
                'IDENTITY(a.doctor) as doctor_id'
            )
            ->addSelect("CONCAT(p.last_name, ' ', p.first_name) as patient_name")
            ->addSelect("CONCAT(u.last_name, ' ', u.first_name) as doctor_name")
            ->join('a.patient', 'p')
            ->join('a.doctor', 'u')
            ->where('a.status = :status')
            ->andWhere('a.start_time BETWEEN CURRENT_TIMESTAMP() AND DATE_ADD(CURRENT_TIMESTAMP(), :minutes_before, \'MINUTE\')')
            ->setParameter('status', 'scheduled')
            ->setParameter('minutes_before', $minutesBefore);

        return $this->formatDatesInResults($qb->getQuery()->getArrayResult());
    }

    public function findByDoctorId(int $doctorId) : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'a.id',
                'a.start_time',
                'a.end_time',
                'a.status',
                'a.notes',
                'a.waitlist_id',
                'a.room_id',
                'a.ehealth_episode_id',
                'a.created_at',
                'a.updated_at',
                'IDENTITY(a.patient) as patient_id',
                'IDENTITY(a.doctor) as doctor_id'
            )
            ->addSelect("CONCAT(p.last_name, ' ', p.first_name) as patient_name")
            ->addSelect("CONCAT(u.last_name, ' ', u.first_name) as doctor_name")
            ->join('a.patient', 'p')
            ->join('a.doctor', 'u')
            ->where('IDENTITY(a.doctor) = :doctor_id')
            ->setParameter('doctor_id', $doctorId)
            ->orderBy('a.start_time', 'DESC');

        return $this->formatDatesInResults($qb->getQuery()->getArrayResult());
    }

    public function findByDoctorIdAndDateRange(int $doctorId, string $start, string $end) : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'a.id',
                'a.start_time',
                'a.end_time',
                'a.status',
                'a.waitlist_id',
                'IDENTITY(a.doctor) as doctor_id'
            )
            ->addSelect("CONCAT(p.last_name, ' ', p.first_name) as patient_name")
            ->addSelect("CONCAT(u.last_name, ' ', u.first_name) as doctor_name")
            ->join('a.patient', 'p')
            ->join('a.doctor', 'u')
            ->where('IDENTITY(a.doctor) = :doctor_id')
            ->andWhere('a.start_time >= :start_time AND a.start_time <= :end_time')
            ->setParameter('doctor_id', $doctorId)
            ->setParameter('start_time', $start)
            ->setParameter('end_time', $end)
            ->orderBy('a.start_time', 'ASC');

        return $this->formatDatesInResults($qb->getQuery()->getArrayResult());
    }

    public function findPatientIdsByDoctor(int $doctorId) : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('DISTINCT IDENTITY(a.patient)')
            ->where('IDENTITY(a.doctor) = :doctor_id')
            ->setParameter('doctor_id', $doctorId);

        $results = $qb->getQuery()->getSingleColumnResult();
        return array_map('intval', $results);
    }

    public function countScheduledByDate(string $date) : int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->andWhere('SUBSTRING(a.start_time, 1, 10) = :date')
            ->setParameter('status', 'scheduled')
            ->setParameter('date', substr($date, 0, 10));

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    public function countScheduledByRange(string $from, string $to) : int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->andWhere('SUBSTRING(a.start_time, 1, 10) BETWEEN :from AND :to')
            ->setParameter('status', 'scheduled')
            ->setParameter('from', substr($from, 0, 10))
            ->setParameter('to', substr($to, 0, 10));

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    public function sumBookedHoursByRange(string $from, string $to) : float
    {
        // For complex date diff, we fall back to raw query using ResultSetMapping or just raw SQL for this specific analytics method.
        // But the task requires QueryBuilder. We can fetch start and end times and calculate in PHP, or use DQL if supported.
        // DQL TIME_TO_SEC(TIMEDIFF(end_time, start_time)) is NOT supported out of the box.
        // Let's use raw SQL specifically for this analytics method or calculate in PHP.
        // I will calculate it in PHP for true DB agnosticism which ORM provides.

        $qb = $this->createQueryBuilder('a')
            ->select('a.start_time', 'a.end_time')
            ->where('a.status = :status')
            ->andWhere('SUBSTRING(a.start_time, 1, 10) BETWEEN :from AND :to')
            ->setParameter('status', 'scheduled')
            ->setParameter('from', substr($from, 0, 10))
            ->setParameter('to', substr($to, 0, 10));

        $results = $qb->getQuery()->getArrayResult();
        $totalHours = 0.0;
        foreach ($results as $row) {
            $totalHours += ($row['end_time']->getTimestamp() - $row['start_time']->getTimestamp()) / 3600;
        }
        return $totalHours;
    }

    public function countDistinctDoctorsByRange(string $from, string $to) : int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(DISTINCT IDENTITY(a.doctor))')
            ->where('a.status = :status')
            ->andWhere('SUBSTRING(a.start_time, 1, 10) BETWEEN :from AND :to')
            ->setParameter('status', 'scheduled')
            ->setParameter('from', substr($from, 0, 10))
            ->setParameter('to', substr($to, 0, 10));

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    public function countDistinctPatientsByRange(string $from, string $to) : int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(DISTINCT IDENTITY(a.patient))')
            ->where('a.status = :status')
            ->andWhere('SUBSTRING(a.start_time, 1, 10) BETWEEN :from AND :to')
            ->setParameter('status', 'scheduled')
            ->setParameter('from', substr($from, 0, 10))
            ->setParameter('to', substr($to, 0, 10));

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    public function countReadmittedPatients(string $from, string $to) : int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.patient) as patient_id', 'COUNT(a.id) as cnt')
            ->where('a.status = :status')
            ->andWhere('SUBSTRING(a.start_time, 1, 10) BETWEEN :from AND :to')
            ->setParameter('status', 'scheduled')
            ->setParameter('from', substr($from, 0, 10))
            ->setParameter('to', substr($to, 0, 10))
            ->groupBy('a.patient')
            ->having('cnt > 1');

        return count($qb->getQuery()->getArrayResult());
    }

    public function getDoctorDailyLoad(string $date) : array
    {
        // Using PHP to process time diff, so fetch the entities using QueryBuilder
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('u.id as doctor_id, u.first_name, u.last_name, a.start_time, a.end_time')
            ->from(\App\Domain\User\User::class, 'u')
            ->join('u.role', 'r')
            ->leftJoin(\App\Domain\Appointment\Appointment::class, 'a', 'WITH', 'u.id = IDENTITY(a.doctor) AND SUBSTRING(a.start_time, 1, 10) = :date AND a.status = :status')
            ->where('r.name = :role_name')
            ->setParameter('date', substr($date, 0, 10))
            ->setParameter('status', 'scheduled')
            ->setParameter('role_name', 'doctor');

        $results = $qb->getQuery()->getArrayResult();

        $doctors = [];
        foreach ($results as $row) {
            $docId = $row['doctor_id'];
            if (! isset($doctors[$docId])) {
                $doctors[$docId] = [
                    'doctor_id' => $docId,
                    'doctor_name' => $row['last_name'] . ' ' . $row['first_name'],
                    'total_appointments' => 0,
                    'total_hours_booked' => 0.0
                ];
            }

            if ($row['start_time'] && $row['end_time']) {
                $doctors[$docId]['total_appointments']++;
                $doctors[$docId]['total_hours_booked'] += ($row['end_time']->getTimestamp() - $row['start_time']->getTimestamp()) / 3600;
            }
        }

        usort($doctors, fn ($a, $b) => $b['total_appointments'] <=> $a['total_appointments']);
        return $doctors;
    }

    public function findUpcoming() : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'a.id',
                'a.start_time',
                'a.end_time',
                'a.status',
                'IDENTITY(a.doctor) as doctor_id'
            )
            ->addSelect("CONCAT(p.last_name, ' ', p.first_name) as patient_name")
            ->addSelect("CONCAT(u.last_name, ' ', u.first_name) as doctor_name")
            ->join('a.patient', 'p')
            ->join('a.doctor', 'u')
            ->where('a.start_time > CURRENT_TIMESTAMP()')
            ->andWhere('a.status = :status')
            ->setParameter('status', 'scheduled')
            ->orderBy('a.start_time', 'ASC')
            ->setMaxResults(10);

        return $this->formatDatesInResults($qb->getQuery()->getArrayResult());
    }

    public function countAppointmentsByDate(string $date) : int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('SUBSTRING(a.start_time, 1, 10) = :date')
            ->setParameter('date', substr($date, 0, 10));

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    public function getSumOfCompletedAppointmentDurationsForDate(string $date) : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.doctor) as doctor_id', 'a.start_time', 'a.end_time')
            ->where('SUBSTRING(a.start_time, 1, 10) = :date')
            ->andWhere('a.status = :status')
            ->setParameter('date', substr($date, 0, 10))
            ->setParameter('status', 'completed');

        $results = $qb->getQuery()->getArrayResult();

        $durations = [];
        foreach ($results as $row) {
            $docId = $row['doctor_id'];
            if (! isset($durations[$docId])) {
                $durations[$docId] = 0;
            }
            if ($row['start_time'] && $row['end_time']) {
                $durations[$docId] += $row['end_time']->getTimestamp() - $row['start_time']->getTimestamp();
            }
        }

        return $durations;
    }

    public function getCompletedAppointmentsWithIcdCodes(string $date) : array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $qb->select('a.id as appointment_id', 'a.patient_id', 'mr.id as medical_record_id', 'GROUP_CONCAT(mri.icd_code_id) as icd_code_ids')
           ->from('appointments', 'a')
           ->join('a', 'medical_records', 'mr', 'a.id = mr.appointment_id')
           ->leftJoin('mr', 'medical_record_icd', 'mri', 'mr.id = mri.medical_record_id')
           ->where('SUBSTRING(a.end_time, 1, 10) = :date')
           ->andWhere('a.status = :status')
           ->setParameter('date', substr($date, 0, 10))
           ->setParameter('status', 'completed')
           ->groupBy('a.id, a.patient_id, mr.id');

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function findPatientSubsequentAppointments(int $patientId, string $afterDate, int $timeframeDays) : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.id as appointment_id', 'a.start_time', 'a.status', 'IDENTITY(mr.id) as medical_record_id')
            ->leftJoin(\App\Domain\MedicalRecord\MedicalRecord::class, 'mr', 'WITH', 'a.id = IDENTITY(mr.appointment)')
            ->where('IDENTITY(a.patient) = :patient_id')
            ->andWhere('a.start_time > :after_date')
            ->andWhere('a.start_time <= DATE_ADD(:after_date, :timeframe_days, \'DAY\')')
            ->setParameter('patient_id', $patientId)
            ->setParameter('after_date', $afterDate)
            ->setParameter('timeframe_days', $timeframeDays)
            ->orderBy('a.start_time', 'ASC');

        return $this->formatDatesInResults($qb->getQuery()->getArrayResult());
    }

    public function findByRoomIdAndDateRange(int $roomId, string $start, string $end) : array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'a.id',
                'a.start_time',
                'a.end_time',
                'a.status',
                'a.room_id'
            )
            ->where('a.room_id = :room_id')
            ->andWhere('a.start_time < :end_time AND a.end_time > :start_time')
            ->setParameter('room_id', $roomId)
            ->setParameter('start_time', $start)
            ->setParameter('end_time', $end)
            ->orderBy('a.start_time', 'ASC');

        return $this->formatDatesInResults($qb->getQuery()->getArrayResult());
    }

    private function formatDatesInResults(array $results) : array
    {
        foreach ($results as &$row) {
            foreach (['start_time', 'end_time', 'created_at', 'updated_at', 'desired_start_time', 'desired_end_time'] as $field) {
                if (isset($row[$field]) && $row[$field] instanceof \DateTimeInterface) {
                    $row[$field] = $row[$field]->format('Y-m-d H:i:s');
                }
            }
        }
        return $results;
    }
}
