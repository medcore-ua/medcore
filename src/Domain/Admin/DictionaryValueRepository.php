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

namespace App\Domain\Admin;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DictionaryValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DictionaryValue::class);
    }

    public function findValuesByDictionaryId(int $dictionaryId) : array
    {
        $qb = $this->createQueryBuilder('dv')
            ->where('dv.dictionary_id = :dictionary_id')
            ->setParameter('dictionary_id', $dictionaryId)
            ->orderBy('dv.order_num', 'ASC')
            ->addOrderBy('dv.label', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findValueById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('dv')
            ->where('dv.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function saveValue(array $data) : ?int
    {
        $value = new DictionaryValue();
        $value->setDictionaryId($data['dictionary_id']);
        $value->setValue($data['value']);
        $value->setLabel($data['label']);
        $value->setOrderNum((int)($data['order_num'] ?? 0));
        $value->setIsActive((bool)($data['is_active'] ?? true));

        try {
            $this->getEntityManager()->persist($value);
            $this->getEntityManager()->flush();
            return $value->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function updateValue(int $id, array $data) : bool
    {
        /** @var DictionaryValue|null $value */
        $value = $this->find($id);
        if (! $value) {
            return false;
        }

        if (isset($data['dictionary_id'])) {
            $value->setDictionaryId($data['dictionary_id']);
        }
        if (isset($data['value'])) {
            $value->setValue($data['value']);
        }
        if (isset($data['label'])) {
            $value->setLabel($data['label']);
        }
        if (isset($data['order_num'])) {
            $value->setOrderNum((int)$data['order_num']);
        }
        if (isset($data['is_active'])) {
            $value->setIsActive((bool)$data['is_active']);
        }

        try {
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function deleteValue(int $id) : bool
    {
        /** @var DictionaryValue|null $value */
        $value = $this->find($id);
        if (! $value) {
            return false;
        }

        try {
            $this->getEntityManager()->remove($value);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
