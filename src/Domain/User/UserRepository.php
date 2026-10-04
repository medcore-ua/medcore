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

use App\Event\EntityChangedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class UserRepository extends ServiceEntityRepository
{
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(ManagerRegistry $registry, EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($registry, User::class);
        $this->eventDispatcher = $eventDispatcher;
    }

    public function findByEmail(string $email) : ?array
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.password_hash', 'IDENTITY(u.role) as role_id', 'u.mfa_enabled', 'u.mfa_type', 'u.mfa_verified_at', 'u.mfa_pending', "CONCAT(u.first_name, ' ', u.last_name) AS full_name")
            ->where('u.email = :email')
            ->setParameter('email', $email);

        return $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
    }

    public function findAll(string $searchTerm = '') : array
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'IDENTITY(u.role) as role_id', 'u.mfa_enabled', "CONCAT(u.first_name, ' ', u.last_name) AS full_name");

        if (! empty($searchTerm)) {
            $qb->where('u.first_name LIKE :term')
               ->orWhere('u.last_name LIKE :term')
               ->orWhere('u.email LIKE :term')
               ->orWhere("CONCAT(u.first_name, ' ', u.last_name) LIKE :term")
               ->setParameter('term', '%' . $searchTerm . '%');
        }

        $qb->orderBy('u.last_name', 'ASC')
           ->addOrderBy('u.first_name', 'ASC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function findAllByRole(string $roleName) : array
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'IDENTITY(u.role) as role_id', "CONCAT(u.first_name, ' ', u.last_name) AS full_name")
            ->join('u.role', 'r')
            ->where('r.name = :role_name')
            ->setParameter('role_name', $roleName)
            ->orderBy('u.last_name', 'ASC')
            ->addOrderBy('u.first_name', 'ASC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function findAllActive() : array
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'IDENTITY(u.role) as role_id', "CONCAT(u.first_name, ' ', u.last_name) AS full_name")
            ->where('u.role IS NOT NULL')
            ->orderBy('u.last_name', 'ASC')
            ->addOrderBy('u.first_name', 'ASC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function findAllDoctors() : array
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'IDENTITY(u.role) as role_id', "CONCAT(u.first_name, ' ', u.last_name) AS full_name")
            ->join('u.role', 'r')
            ->where('r.name = :role_name')
            ->setParameter('role_name', 'doctor')
            ->orderBy('u.last_name', 'ASC')
            ->addOrderBy('u.first_name', 'ASC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'IDENTITY(u.role) as role_id', 'u.password_hash', 'u.created_at', 'u.updated_at', 'u.profile_photo_path', 'u.mfa_enabled', 'u.mfa_type', 'u.mfa_verified_at', 'u.mfa_pending', "CONCAT(u.first_name, ' ', u.last_name) AS full_name")
            ->where('u.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if ($result) {
            // Cast timestamps to DateTime objects for safer usage in views if they are returned as strings.
            // Doctrine usually returns DateTime objects for datetime types if hydrated as array? Actually, it does!
            // But just in case, we do the check from the legacy code:
            if (! empty($result['created_at']) && is_string($result['created_at'])) {
                $result['created_at'] = new \DateTimeImmutable($result['created_at']);
            }
            if (! empty($result['updated_at']) && is_string($result['updated_at'])) {
                $result['updated_at'] = new \DateTimeImmutable($result['updated_at']);
            }
        }

        return $result;
    }

    public function findByEmailExcludingId(string $email, int $id) : ?array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.email = :email')
            ->andWhere('u.id != :id')
            ->setParameter('email', $email)
            ->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
    }

    public function save(array $data) : int|false
    {
        $username = $data['username'] ?? $data['email'];

        $user = new User();
        $user->setFirstName($data['first_name']);
        $user->setLastName($data['last_name']);
        $user->setEmail($data['email']);
        $user->setUsername($username);

        if (! empty($data['password'])) {
            $user->setPasswordHash(password_hash($data['password'], PASSWORD_DEFAULT));
        }

        if (! empty($data['role_id'])) {
            $role = $this->getEntityManager()->getReference(\App\Domain\User\Role::class, $data['role_id']);
            $user->setRole($role);
        }

        try {
            $this->getEntityManager()->persist($user);
            $this->getEntityManager()->flush();

            $id = $user->getId();
            $this->eventDispatcher->dispatch(new EntityChangedEvent('user', $id, 'create', null, $data));
            return $id;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function update(int $id, array $data) : bool
    {
        $oldData = $this->findById($id);
        if (! $oldData) {
            return false;
        }

        /** @var User|null $user */
        $user = $this->find($id);
        if (! $user) {
            return false;
        }

        if (isset($data['first_name'])) {
            $user->setFirstName($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $user->setLastName($data['last_name']);
        }
        if (isset($data['email'])) {
            $user->setEmail($data['email']);
        }
        if (isset($data['role_id'])) {
            $role = $this->getEntityManager()->getReference(\App\Domain\User\Role::class, $data['role_id']);
            $user->setRole($role);
        }
        if (! empty($data['password'])) {
            $user->setPasswordHash(password_hash($data['password'], PASSWORD_DEFAULT));
        }

        try {
            $this->getEntityManager()->flush();
            $this->eventDispatcher->dispatch(new EntityChangedEvent('user', $id, 'update', $oldData, $data));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function delete(int $id) : bool
    {
        $oldData = $this->findById($id);
        if (! $oldData) {
            return false;
        }

        /** @var User|null $user */
        $user = $this->find($id);
        if (! $user) {
            return false;
        }

        try {
            $this->getEntityManager()->remove($user);
            $this->getEntityManager()->flush();
            $this->eventDispatcher->dispatch(new EntityChangedEvent('user', $id, 'delete', $oldData, null));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function countUsers() : int
    {
        return $this->count([]);
    }

    public function updateProfilePhotoPath(int $userId, ?string $path) : bool
    {
        /** @var User|null $user */
        $user = $this->find($userId);
        if (! $user) {
            return false;
        }

        $user->setProfilePhotoPath($path);

        try {
            $this->getEntityManager()->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function findRoleIdByName(string $roleName) : ?int
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('r.id')
           ->from(\App\Domain\User\Role::class, 'r')
           ->where('r.name = :role_name')
           ->setParameter('role_name', $roleName)
           ->setMaxResults(1);

        $result = $qb->getQuery()->getOneOrNullResult();
        return $result ? (int)$result['id'] : null;
    }
}
