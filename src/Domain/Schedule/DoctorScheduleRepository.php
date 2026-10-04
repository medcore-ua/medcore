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

namespace App\Domain\Schedule;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DoctorScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DoctorSchedule::class);
    }

    public function create(array $data) : bool
    {
        $em = $this->getEntityManager();
        $schedule = new DoctorSchedule();
        $schedule->setDoctorId($data['doctor_id']);
        $schedule->setDayOfWeek($data['day_of_week']);
        $schedule->setStartTime(new \DateTime($data['start_time']));
        $schedule->setEndTime(new \DateTime($data['end_time']));
        $schedule->setIsAvailable($data['is_available'] ?? true);

        $em->persist($schedule);
        $em->flush();
        return true;
    }

    public function findByDoctorAndDay(int $doctorId, int $dayOfWeek) : ?array
    {
        $qb = $this->createQueryBuilder('ds')
            ->where('ds.doctor_id = :doctor_id')
            ->andWhere('ds.day_of_week = :day_of_week')
            ->setParameter('doctor_id', $doctorId)
            ->setParameter('day_of_week', $dayOfWeek);

        $result = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if ($result) {
            if ($result['start_time'] instanceof \DateTimeInterface) {
                $result['start_time'] = $result['start_time']->format('H:i:s');
            }
            if ($result['end_time'] instanceof \DateTimeInterface) {
                $result['end_time'] = $result['end_time']->format('H:i:s');
            }
        }
        return $result;
    }

    public function findByDoctor(int $doctorId) : array
    {
        $qb = $this->createQueryBuilder('ds')
            ->where('ds.doctor_id = :doctor_id')
            ->setParameter('doctor_id', $doctorId)
            ->orderBy('ds.day_of_week', 'ASC');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function ($row) {
            if ($row['start_time'] instanceof \DateTimeInterface) {
                $row['start_time'] = $row['start_time']->format('H:i:s');
            }
            if ($row['end_time'] instanceof \DateTimeInterface) {
                $row['end_time'] = $row['end_time']->format('H:i:s');
            }
            return $row;
        }, $results);
    }

    public function update(int $id, array $data) : bool
    {
        $em = $this->getEntityManager();
        $schedule = $em->getRepository(DoctorSchedule::class)->find($id);
        if (! $schedule) {
            return false;
        }

        $schedule->setDoctorId($data['doctor_id']);
        $schedule->setDayOfWeek($data['day_of_week']);
        $schedule->setStartTime(new \DateTime($data['start_time']));
        $schedule->setEndTime(new \DateTime($data['end_time']));
        $schedule->setIsAvailable($data['is_available']);

        $em->flush();
        return true;
    }

    public function delete(int $id) : bool
    {
        $qb = $this->createQueryBuilder('ds')
            ->delete()
            ->where('ds.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->execute() > 0;
    }
}
