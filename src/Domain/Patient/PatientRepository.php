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

namespace App\Domain\Patient;

use App\Event\EntityChangedEvent;
use App\Shared\Service\AuditLogger as CoreAuditLogger;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class PatientRepository extends ServiceEntityRepository
{
    private CoreAuditLogger $auditLogger;
    private ?string $lastError = null;
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(ManagerRegistry $registry, CoreAuditLogger $auditLogger, EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($registry, Patient::class);
        $this->auditLogger = $auditLogger;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function findAll(string $searchTerm = '') : array
    {
        $qb = $this->createQueryBuilder('p');

        if (! empty($searchTerm)) {
            // Doctrine doesn't natively support MATCH AGAINST without custom DQL functions,
            // so we fallback to standard LIKE searches for ORM portability.
            $qb->where('p.last_name LIKE :term')
               ->orWhere('p.first_name LIKE :term')
               ->orWhere('p.middle_name LIKE :term')
               ->orWhere('p.phone LIKE :term')
               ->setParameter('term', '%' . $searchTerm . '%');
        }

        $qb->orderBy('p.last_name', 'ASC')
           ->addOrderBy('p.first_name', 'ASC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function findByIds(array $ids, string $searchTerm = '') : array
    {
        if (empty($ids)) {
            return [];
        }

        $qb = $this->createQueryBuilder('p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids);

        if (! empty($searchTerm)) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('p.last_name', ':term'),
                    $qb->expr()->like('p.first_name', ':term'),
                    $qb->expr()->like('p.middle_name', ':term'),
                    $qb->expr()->like('p.phone', ':term')
                )
            )->setParameter('term', '%' . $searchTerm . '%');
        }

        $qb->orderBy('p.last_name', 'ASC')
           ->addOrderBy('p.first_name', 'ASC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function countAll() : int
    {
        return $this->count([]);
    }

    public function save(array $data) : int|false
    {
        $this->lastError = null;

        if (isset($data['birth_date']) && '1900-01-01' !== $data['birth_date']) {
            if ($this->findByCredentials($data['last_name'], $data['first_name'], $data['birth_date'])) {
                $this->lastError = 'patient_exists';
                return false;
            }
        }

        if (! empty($data['tax_id']) && $this->findByTaxId($data['tax_id'])) {
            $this->lastError = 'tax_id_exists';
            return false;
        }

        $patient = new Patient();
        $this->hydrateEntity($patient, $data);

        try {
            $this->getEntityManager()->persist($patient);
            $this->getEntityManager()->flush();

            $id = $patient->getId();
            $this->eventDispatcher->dispatch(new EntityChangedEvent('patient', $id, 'create', null, $data));
            return $id;
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), '1062')) {
                $this->lastError = 'duplicate_key';
                return false;
            }
            throw $e;
        }
    }

    public function findByCredentials(string $lastName, string $firstName, string $birthDate) : ?array
    {
        try {
            $dt = new \DateTime($birthDate);
        } catch (\Exception $e) {
            return null;
        }

        $qb = $this->createQueryBuilder('p')
            ->where('p.last_name = :last_name')
            ->andWhere('p.first_name = :first_name')
            ->andWhere('p.birth_date = :birth_date')
            ->setParameter('last_name', $lastName)
            ->setParameter('first_name', $firstName)
            ->setParameter('birth_date', $dt->format('Y-m-d'));

        $result = $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
        return $result;
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
    }

    public function findByTaxId(string $taxId, ?int $excludeId = null) : ?array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.tax_id = :tax_id')
            ->setParameter('tax_id', $taxId);

        if (null !== $excludeId) {
            $qb->andWhere('p.id != :exclude_id')
               ->setParameter('exclude_id', $excludeId);
        }

        return $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
    }

    public function findByEmail(string $email) : ?array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.email = :email')
            ->setParameter('email', $email);

        return $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);
    }

    public function update(int $id, array $data) : bool
    {
        $this->lastError = null;

        $oldPatientArray = $this->findById($id);
        $oldStatus = $oldPatientArray['status'] ?? null;

        if (! empty($data['tax_id']) && $this->findByTaxId($data['tax_id'], $id)) {
            $this->lastError = 'tax_id_exists';
            return false;
        }

        /** @var Patient|null $patient */
        $patient = $this->find($id);
        if (! $patient) {
            return false;
        }

        $this->hydrateEntity($patient, $data);

        try {
            $this->getEntityManager()->flush();
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), '1062')) {
                $this->lastError = 'duplicate_key';
                return false;
            }
            throw $e;
        }

        $newStatus = $data['status'] ?? 'active';
        if ($oldStatus !== $newStatus) {
            $this->auditLogger->log('patient', $id, 'status_change', $oldStatus, $newStatus);
        }

        $this->eventDispatcher->dispatch(new EntityChangedEvent('patient', $id, 'update', $oldPatientArray, $data));

        return true;
    }

    public function getLastError() : ?string
    {
        return $this->lastError;
    }

    public function findAllActive() : array
    {
        $qb = $this->createQueryBuilder('p')
            ->select("p.id", "CONCAT(p.last_name, ' ', p.first_name) as full_name")
            ->where('p.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('p.last_name', 'ASC')
            ->addOrderBy('p.first_name', 'ASC');

        return $qb->getQuery()->getResult(Query::HYDRATE_ARRAY);
    }

    public function updateStatus(int $id, string $status) : bool
    {
        $oldPatientArray = $this->findById($id);
        $oldStatus = $oldPatientArray['status'] ?? null;

        /** @var Patient|null $patient */
        $patient = $this->find($id);
        if (! $patient) {
            return false;
        }

        $patient->setStatus($status);
        $this->getEntityManager()->flush();

        if ($oldStatus !== $status) {
            $this->auditLogger->log('patient', $id, 'status_change', $oldStatus, $status);
            $this->eventDispatcher->dispatch(new EntityChangedEvent('patient', $id, 'update', $oldPatientArray, ['status' => $status]));
        }
        return true;
    }

    private function hydrateEntity(Patient $patient, array $data) : void
    {
        if (isset($data['first_name'])) {
            $patient->setFirstName($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $patient->setLastName($data['last_name']);
        }
        if (array_key_exists('middle_name', $data)) {
            $patient->setMiddleName($data['middle_name']);
        }
        if (isset($data['birth_date'])) {
            try {
                $patient->setBirthDate(new \DateTime($data['birth_date']));
            } catch (\Exception $e) {
            }
        }
        if (isset($data['gender'])) {
            $patient->setGender($data['gender']);
        }
        if (isset($data['phone'])) {
            $patient->setPhone($data['phone']);
        }
        if (array_key_exists('email', $data)) {
            $patient->setEmail($data['email']);
        }
        if (array_key_exists('address', $data)) {
            $patient->setAddress($data['address']);
        }
        if (array_key_exists('tax_id', $data)) {
            $patient->setTaxId($data['tax_id']);
        }
        if (array_key_exists('document_id', $data)) {
            $patient->setDocumentId($data['document_id']);
        }
        if (array_key_exists('marital_status', $data)) {
            $patient->setMaritalStatus($data['marital_status']);
        }
        if (isset($data['status'])) {
            $patient->setStatus($data['status']);
        }
    }
}
