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

namespace App\Domain\Kpi;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class KpiResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KpiResult::class);
    }

    public function save(array $data) : ?int
    {
        $em = $this->getEntityManager();
        try {
            $kpiResult = $this->findOneBy([
                'kpi_id' => $data['kpi_id'],
                'user_id' => $data['user_id'],
                'period_start' => new \DateTime($data['period_start']),
                'period_end' => new \DateTime($data['period_end']),
            ]);

            if (! $kpiResult) {
                $kpiResult = new KpiResult();
                $kpiResult->setKpiId($data['kpi_id']);
                $kpiResult->setUserId($data['user_id']);
                $kpiResult->setPeriodStart(new \DateTime($data['period_start']));
                $kpiResult->setPeriodEnd(new \DateTime($data['period_end']));
            }

            $kpiResult->setCalculatedValue((float)$data['calculated_value']);
            if (isset($data['notes'])) {
                $kpiResult->setNotes($data['notes']);
            }

            $em->persist($kpiResult);
            $em->flush();

            return $kpiResult->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function findResultsForUser(int $userId, ?string $periodStart = null, ?string $periodEnd = null) : array
    {
        $qb = $this->createQueryBuilder('kr');
        $qb->select(
            'kr.id',
            'kr.kpi_id',
            'kr.user_id',
            'kr.period_start',
            'kr.period_end',
            'kr.calculated_value',
            'kr.notes',
            'kd.name as kpi_name',
            'kd.unit'
        )
           ->join(\App\Domain\Kpi\KpiDefinition::class, 'kd', 'WITH', 'kr.kpi_id = kd.id')
           ->where('kr.user_id = :user_id')
           ->setParameter('user_id', $userId);

        if ($periodStart) {
            $qb->andWhere('kr.period_start >= :period_start')
               ->setParameter('period_start', $periodStart);
        }
        if ($periodEnd) {
            $qb->andWhere('kr.period_end <= :period_end')
               ->setParameter('period_end', $periodEnd);
        }
        $qb->orderBy('kr.period_start', 'DESC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findAllResults() : array
    {
        $qb = $this->createQueryBuilder('kr');
        $qb->select(
            'kr.id',
            'kr.kpi_id',
            'kr.user_id',
            'kr.period_start',
            'kr.period_end',
            'kr.calculated_value',
            'kr.notes',
            'kd.name AS kpi_name',
            'kd.unit',
            'CONCAT(u.last_name, CONCAT(\' \', u.first_name)) AS user_name'
        )
        ->join(\App\Domain\Kpi\KpiDefinition::class, 'kd', 'WITH', 'kr.kpi_id = kd.id')
        ->join(\App\Domain\User\User::class, 'u', 'WITH', 'kr.user_id = u.id')
        ->orderBy('kr.period_start', 'DESC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findLatestResult(int $kpiId, string $periodType) : ?array
    {
        $qb = $this->createQueryBuilder('kr');
        $qb->where('kr.kpi_id = :kpi_id')
           ->setParameter('kpi_id', $kpiId);

        switch ($periodType) {
            case 'day':
                $qb->andWhere('kr.period_start = kr.period_end');
                break;
            case 'week':
                $qb->andWhere('DATE_DIFF(kr.period_end, kr.period_start) = 6');
                break;
            case 'month':
                $qb->andWhere('DATE_DIFF(kr.period_end, kr.period_start) = 29');
                break;
            default:
                break;
        }

        $qb->orderBy('kr.period_end', 'DESC')
           ->addOrderBy('kr.id', 'DESC')
           ->setMaxResults(1);

        $result = $qb->getQuery()->getArrayResult();
        return $result ? $result[0] : null;
    }

    public function findResultForPreviousPeriod(int $kpiId, string $currentPeriodEnd, string $periodType = 'day') : ?array
    {
        $qb = $this->createQueryBuilder('kr');
        $qb->where('kr.kpi_id = :kpi_id')
           ->andWhere('kr.period_end < :current_period_end')
           ->setParameter('kpi_id', $kpiId)
           ->setParameter('current_period_end', $currentPeriodEnd)
           ->orderBy('kr.period_end', 'DESC')
           ->addOrderBy('kr.id', 'DESC')
           ->setMaxResults(1);

        $result = $qb->getQuery()->getArrayResult();
        return $result ? $result[0] : null;
    }
}
