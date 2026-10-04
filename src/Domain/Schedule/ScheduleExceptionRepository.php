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

class ScheduleExceptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduleException::class);
    }

    public function create(array $data) : bool
    {
        $em = $this->getEntityManager();
        $exception = new ScheduleException();
        $exception->setDoctorId($data['doctor_id']);
        $exception->setExceptionDate(new \DateTime($data['exception_date']));
        $exception->setStartTime(new \DateTime($data['start_time']));
        $exception->setEndTime(new \DateTime($data['end_time']));
        $exception->setIsAvailable($data['is_available'] ?? false);
        $exception->setNotes($data['notes'] ?? null);

        $em->persist($exception);
        $em->flush();
        return true;
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('se')
            ->where('se.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if ($result) {
            if ($result['exception_date'] instanceof \DateTimeInterface) {
                $result['exception_date'] = $result['exception_date']->format('Y-m-d');
            }
            if ($result['start_time'] instanceof \DateTimeInterface) {
                $result['start_time'] = $result['start_time']->format('H:i:s');
            }
            if ($result['end_time'] instanceof \DateTimeInterface) {
                $result['end_time'] = $result['end_time']->format('H:i:s');
            }
        }
        return $result;
    }

    public function findByDoctorAndDate(int $doctorId, string $date) : array
    {
        $qb = $this->createQueryBuilder('se')
            ->where('se.doctor_id = :doctor_id')
            ->andWhere('se.exception_date = :exception_date')
            ->setParameter('doctor_id', $doctorId)
            ->setParameter('exception_date', $date)
            ->orderBy('se.start_time', 'ASC');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function ($row) {
            if ($row['exception_date'] instanceof \DateTimeInterface) {
                $row['exception_date'] = $row['exception_date']->format('Y-m-d');
            }
            if ($row['start_time'] instanceof \DateTimeInterface) {
                $row['start_time'] = $row['start_time']->format('H:i:s');
            }
            if ($row['end_time'] instanceof \DateTimeInterface) {
                $row['end_time'] = $row['end_time']->format('H:i:s');
            }
            return $row;
        }, $results);
    }

    public function findByDoctorAndDateRange(int $doctorId, string $startDate, string $endDate) : array
    {
        $qb = $this->createQueryBuilder('se')
            ->where('se.doctor_id = :doctor_id')
            ->andWhere('se.exception_date BETWEEN :start_date AND :end_date')
            ->setParameter('doctor_id', $doctorId)
            ->setParameter('start_date', $startDate)
            ->setParameter('end_date', $endDate)
            ->orderBy('se.exception_date', 'ASC')
            ->addOrderBy('se.start_time', 'ASC');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function ($row) {
            if ($row['exception_date'] instanceof \DateTimeInterface) {
                $row['exception_date'] = $row['exception_date']->format('Y-m-d');
            }
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
        $exception = $em->getRepository(ScheduleException::class)->find($id);
        if (! $exception) {
            return false;
        }

        $exception->setDoctorId($data['doctor_id']);
        $exception->setExceptionDate(new \DateTime($data['exception_date']));
        $exception->setStartTime(new \DateTime($data['start_time']));
        $exception->setEndTime(new \DateTime($data['end_time']));
        $exception->setIsAvailable($data['is_available']);
        $exception->setNotes($data['notes']);

        $em->flush();
        return true;
    }

    public function delete(int $id) : bool
    {
        $qb = $this->createQueryBuilder('se')
            ->delete()
            ->where('se.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->execute() > 0;
    }
}
