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

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WaitlistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Waitlist::class);
    }

    public function findWaitlistById(int $id) : ?array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select(
                'wl.id',
                'wl.ticket_number',
                'wl.desired_start_time',
                'wl.desired_end_time',
                'wl.notes',
                'wl.contact_phone',
                'wl.contact_email',
                'wl.status',
                'wl.created_at',
                'wl.patient_id',
                'wl.desired_doctor_id'
            )
            ->addSelect("COALESCE(CONCAT(p.last_name, ' ', p.first_name), 'Невідомий пацієнт') as patient_name")
            ->addSelect("COALESCE(CONCAT(u.last_name, ' ', u.first_name), 'Будь-який') as doctor_name")
            ->from(Waitlist::class, 'wl')
            ->leftJoin(\App\Domain\Patient\Patient::class, 'p', \Doctrine\ORM\Query\Expr\Join::WITH, 'wl.patient_id = p.id')
            ->leftJoin(\App\Domain\User\User::class, 'u', \Doctrine\ORM\Query\Expr\Join::WITH, 'wl.desired_doctor_id = u.id')
            ->where('wl.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        return $result ? $this->formatDatesInResults([$result])[0] : null;
    }

    public function updateWaitlistStatus(int $id, string $status) : bool
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->update(Waitlist::class, 'wl')
            ->set('wl.status', ':status')
            ->where('wl.id = :id')
            ->setParameter('status', $status)
            ->setParameter('id', $id);

        return $qb->getQuery()->execute() > 0;
    }

    public function addToWaitlist(array $data) : bool
    {
        $ticket = $data['ticket_number'] ?? $this->generateWaitlistTicket();

        $waitlist = new Waitlist();
        $waitlist->setTicketNumber($ticket);

        $waitlist->setPatientId((int)$data['patient_id']);

        if (! empty($data['desired_doctor_id'])) {
            $waitlist->setDesiredDoctorId((int)$data['desired_doctor_id']);
        }

        if (! empty($data['desired_start_time'])) {
            $waitlist->setDesiredStartTime(new \DateTime($data['desired_start_time']));
        }
        if (! empty($data['desired_end_time'])) {
            $waitlist->setDesiredEndTime(new \DateTime($data['desired_end_time']));
        }

        $waitlist->setNotes($data['notes'] ?? null);
        $waitlist->setContactPhone($data['contact_phone'] ?? null);
        $waitlist->setContactEmail($data['contact_email'] ?? null);

        try {
            $this->getEntityManager()->persist($waitlist);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getWaitlistEntries(?string $status = 'pending') : array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select(
                'wl.id',
                'wl.ticket_number',
                'wl.desired_start_time',
                'wl.desired_end_time',
                'wl.notes',
                'wl.contact_phone',
                'wl.contact_email',
                'wl.status',
                'wl.created_at',
                'p.status as patient_status',
                'p.phone as patient_phone',
                'p.email as patient_email'
            )
            ->addSelect("COALESCE(CONCAT(p.last_name, ' ', p.first_name), 'Невідомий пацієнт') as patient_name")
            ->addSelect("COALESCE(CONCAT(u.last_name, ' ', u.first_name), 'Будь-який') as doctor_name")
            ->from(Waitlist::class, 'wl')
            ->leftJoin(\App\Domain\Patient\Patient::class, 'p', \Doctrine\ORM\Query\Expr\Join::WITH, 'wl.patient_id = p.id')
            ->leftJoin(\App\Domain\User\User::class, 'u', \Doctrine\ORM\Query\Expr\Join::WITH, 'wl.desired_doctor_id = u.id')
            ->orderBy('wl.created_at', 'ASC');

        if (null !== $status) {
            $qb->where('wl.status = :status')
               ->setParameter('status', $status);
        }

        return $this->formatDatesInResults($qb->getQuery()->getArrayResult());
    }

    public function generateWaitlistTicket() : string
    {
        $year = date('Y');
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(wl.id)')
            ->from(Waitlist::class, 'wl')
            ->where('SUBSTRING(wl.created_at, 1, 4) = :year')
            ->setParameter('year', $year);

        $count = (int)$qb->getQuery()->getSingleScalarResult() + 1;
        return sprintf('WL-%s-%05d', $year, $count);
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
