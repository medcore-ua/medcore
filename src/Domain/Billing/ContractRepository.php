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

namespace App\Domain\Billing;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contract::class);
    }

    public function findAll() : array
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.created_at', 'DESC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function save(array $data) : ?int
    {
        $contract = new Contract();
        $contract->setTitle($data['title']);
        $contract->setDescription($data['description'] ?? null);

        try {
            if (! empty($data['start_date'])) {
                $contract->setStartDate(new \DateTime($data['start_date']));
            }
            if (! empty($data['end_date'])) {
                $contract->setEndDate(new \DateTime($data['end_date']));
            }
        } catch (\Exception $e) {
            // ignore invalid dates
        }

        $contract->setPartyA($data['party_a'] ?? null);
        $contract->setPartyB($data['party_b'] ?? null);
        $contract->setFilePath($data['file_path'] ?? null);
        $contract->setStatus($data['status'] ?? 'active');

        try {
            $this->getEntityManager()->persist($contract);
            $this->getEntityManager()->flush();
            return $contract->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function update(int $id, array $data) : bool
    {
        /** @var Contract|null $contract */
        $contract = $this->find($id);
        if (! $contract) {
            return false;
        }

        if (isset($data['title'])) {
            $contract->setTitle($data['title']);
        }
        if (array_key_exists('description', $data)) {
            $contract->setDescription($data['description']);
        }

        try {
            if (isset($data['start_date'])) {
                $contract->setStartDate(new \DateTime($data['start_date']));
            }
            if (array_key_exists('end_date', $data)) {
                $contract->setEndDate($data['end_date'] ? new \DateTime($data['end_date']) : null);
            }
        } catch (\Exception $e) {
            // ignore invalid dates
        }

        if (array_key_exists('party_a', $data)) {
            $contract->setPartyA($data['party_a']);
        }
        if (array_key_exists('party_b', $data)) {
            $contract->setPartyB($data['party_b']);
        }
        if (array_key_exists('file_path', $data)) {
            $contract->setFilePath($data['file_path']);
        }
        if (isset($data['status'])) {
            $contract->setStatus($data['status']);
        }

        try {
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function delete(int $id) : bool
    {
        /** @var Contract|null $contract */
        $contract = $this->find($id);
        if (! $contract) {
            return false;
        }

        try {
            $this->getEntityManager()->remove($contract);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
