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

class AuthConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthConfig::class);
    }

    public function findAll() : array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.provider', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findActive() : array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.is_active = 1')
            ->orderBy('a.provider', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function findByProvider(string $provider) : ?array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.provider = :provider')
            ->setParameter('provider', $provider);

        return $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function save(array $data) : ?int
    {
        $config = new AuthConfig();
        $config->setProvider($data['provider']);
        $config->setClientId($data['client_id'] ?? null);
        $config->setClientSecret($data['client_secret'] ?? null);
        $config->setIsActive((bool)($data['is_active'] ?? false));
        $config->setConfig($data['config'] ?? null);

        try {
            $this->getEntityManager()->persist($config);
            $this->getEntityManager()->flush();
            return $config->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function update(int $id, array $data) : bool
    {
        /** @var AuthConfig|null $config */
        $config = $this->find($id);
        if (! $config) {
            return false;
        }

        if (isset($data['provider'])) {
            $config->setProvider($data['provider']);
        }
        if (array_key_exists('client_id', $data)) {
            $config->setClientId($data['client_id']);
        }
        if (array_key_exists('client_secret', $data)) {
            $config->setClientSecret($data['client_secret']);
        }
        if (isset($data['is_active'])) {
            $config->setIsActive((bool)$data['is_active']);
        }
        if (array_key_exists('config', $data)) {
            $config->setConfig($data['config']);
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
        /** @var AuthConfig|null $config */
        $config = $this->find($id);
        if (! $config) {
            return false;
        }

        try {
            $this->getEntityManager()->remove($config);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
