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

namespace App\Domain\User;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

class UserOAuthIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserOAuthIdentity::class);
    }

    public function findByProviderAndProviderId(string $provider, string $providerId) : ?array
    {
        $qb = $this->createQueryBuilder('uoi')
            ->where('uoi.provider = :provider')
            ->andWhere('uoi.provider_id = :provider_id')
            ->setParameter('provider', $provider)
            ->setParameter('provider_id', $providerId);

        $result = $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
        return $result ?: null;
    }

    public function findByUserIdAndProvider(int $userId, string $provider) : ?array
    {
        $qb = $this->createQueryBuilder('uoi')
            ->where('uoi.user = :user_id')
            ->andWhere('uoi.provider = :provider')
            ->setParameter('user_id', $userId)
            ->setParameter('provider', $provider);

        $result = $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
        return $result ?: null;
    }

    public function create(int $userId, string $provider, string $providerId) : bool
    {
        $identity = new UserOAuthIdentity();

        $user = $this->getEntityManager()->getReference(\App\Domain\User\User::class, $userId);
        $identity->setUser($user);

        $identity->setProvider($provider);
        $identity->setProviderId($providerId);
        $identity->setCreatedAt(new \DateTime());

        try {
            $this->getEntityManager()->persist($identity);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function delete(int $id) : bool
    {
        $identity = $this->find($id);

        if (! $identity) {
            return false;
        }

        try {
            $this->getEntityManager()->remove($identity);
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function deleteByUserIdAndProvider(int $userId, string $provider) : bool
    {
        $qb = $this->createQueryBuilder('uoi')
            ->delete()
            ->where('uoi.user = :user_id')
            ->andWhere('uoi.provider = :provider')
            ->setParameter('user_id', $userId)
            ->setParameter('provider', $provider);

        try {
            $qb->getQuery()->execute();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function findAllByUserId(int $userId) : array
    {
        $qb = $this->createQueryBuilder('uoi')
            ->where('uoi.user = :user_id')
            ->setParameter('user_id', $userId);

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }
}
