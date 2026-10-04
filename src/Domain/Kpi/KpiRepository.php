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

class KpiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KpiDefinition::class);
    }

    // --- KPI Definitions ---
    public function findAllKpiDefinitions() : array
    {
        return $this->createQueryBuilder('k')
            ->orderBy('k.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function findActiveKpiDefinitions() : array
    {
        return $this->createQueryBuilder('k')
            ->where('k.is_active = 1')
            ->orderBy('k.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function findKpiDefinitionById(int $id) : ?array
    {
        $result = $this->createQueryBuilder('k')
            ->where('k.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getArrayResult();
        return $result ? $result[0] : null;
    }

    public function saveKpiDefinition(array $data) : ?int
    {
        $em = $this->getEntityManager();
        try {
            $kpi = new KpiDefinition();
            $kpi->setName($data['name']);
            if (isset($data['description'])) {
                $kpi->setDescription($data['description']);
            }
            $kpi->setKpiType($data['kpi_type']);
            if (isset($data['target_value'])) {
                $kpi->setTargetValue((float)$data['target_value']);
            }
            if (isset($data['unit'])) {
                $kpi->setUnit($data['unit']);
            }
            $kpi->setIsActive((bool)($data['is_active'] ?? true));
            $kpi->setPeriod($data['period'] ?? 'day');

            $em->persist($kpi);
            $em->flush();

            return $kpi->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function updateKpiDefinition(int $id, array $data) : bool
    {
        $em = $this->getEntityManager();
        try {
            $kpi = $this->find($id);
            if (! $kpi) {
                return false;
            }

            $kpi->setName($data['name']);
            if (array_key_exists('description', $data)) {
                $kpi->setDescription($data['description']);
            }
            $kpi->setKpiType($data['kpi_type']);
            if (array_key_exists('target_value', $data)) {
                $kpi->setTargetValue((string)$data['target_value']);
            }
            if (array_key_exists('unit', $data)) {
                $kpi->setUnit($data['unit']);
            }
            $kpi->setIsActive((bool)($data['is_active'] ?? true));
            $kpi->setPeriod($data['period'] ?? 'day');

            $em->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function deleteKpiDefinition(int $id) : bool
    {
        $em = $this->getEntityManager();
        try {
            $kpi = $this->find($id);
            if (! $kpi) {
                return false;
            }

            $em->remove($kpi);
            $em->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
