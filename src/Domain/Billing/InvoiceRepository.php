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

use App\Event\EntityChangedEvent;
use App\Event\PatientNotificationEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class InvoiceRepository extends ServiceEntityRepository
{
    private EventDispatcherInterface $eventDispatcher;
    private PaymentRepository $paymentRepository;

    public function __construct(ManagerRegistry $registry, EventDispatcherInterface $eventDispatcher, PaymentRepository $paymentRepository)
    {
        parent::__construct($registry, Invoice::class);
        $this->paymentRepository = $paymentRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function findAll(string $searchTerm = '') : array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.id', 'p.last_name', 'p.first_name', 'i.amount', 'i.status', 'i.issued_date')
            ->join(\App\Domain\Patient\Patient::class, 'p', \Doctrine\ORM\Query\Expr\Join::WITH, 'i.patient_id = p.id');

        if (! empty($searchTerm)) {
            $orX = $qb->expr()->orX(
                $qb->expr()->like('p.last_name', ':term'),
                $qb->expr()->like('p.first_name', ':term'),
                $qb->expr()->like("CONCAT(p.last_name, ' ', p.first_name)", ':term'),
                $qb->expr()->like('i.status', ':term')
            );
            if (is_numeric($searchTerm)) {
                $orX->add($qb->expr()->eq('i.id', ':idExact'));
                $qb->setParameter('idExact', (int)$searchTerm);
            }
            $qb->where($orX);
            $qb->setParameter('term', '%' . $searchTerm . '%');
        }

        $qb->orderBy('i.issued_date', 'DESC');
        $results = $qb->getQuery()->getArrayResult();

        return array_map(function ($row) {
            $row['patient_name'] = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? ''));
            if ($row['issued_date'] instanceof \DateTimeInterface) {
                $row['issued_date'] = $row['issued_date']->format('Y-m-d H:i:s');
            }
            return $row;
        }, $results);
    }

    public function save(array $data) : int
    {
        $invoice = new Invoice();
        $invoice->setPatientId((int)$data['patient_id']);
        $invoice->setAppointmentId(! empty($data['appointment_id']) ? (int)$data['appointment_id'] : null);
        $invoice->setMedicalRecordId(! empty($data['medical_record_id']) ? (int)$data['medical_record_id'] : null);
        $invoice->setAmount((float)$data['amount']);
        $invoice->setStatus($data['status'] ?? 'pending');
        $invoice->setNotes($data['notes'] ?? null);
        $invoice->setType($data['type'] ?? 'invoice');

        $this->getEntityManager()->persist($invoice);
        $this->getEntityManager()->flush();

        $invoiceId = $invoice->getId();

        $this->eventDispatcher->dispatch(new EntityChangedEvent('invoice', $invoiceId, 'create', null, $data));
        $this->eventDispatcher->dispatch(new PatientNotificationEvent(
            $data['patient_id'],
            'invoice_created',
            sprintf('Створено рахунок на суму %.2f грн', $data['amount']),
            ['invoice_id' => $invoiceId]
        ));

        return $invoiceId;
    }

    public function updateInsuranceDue(int $invoiceId, float $insuranceDue, float $patientDue) : bool
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->update(Invoice::class, 'i')
            ->set('i.insurance_due', ':insurance_due')
            ->set('i.patient_due', ':patient_due')
            ->where('i.id = :id')
            ->setParameter('insurance_due', $insuranceDue)
            ->setParameter('patient_due', $patientDue)
            ->setParameter('id', $invoiceId);

        return $qb->getQuery()->execute() > 0;
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i', 'p.last_name', 'p.first_name')
            ->join(\App\Domain\Patient\Patient::class, 'p', \Doctrine\ORM\Query\Expr\Join::WITH, 'i.patient_id = p.id')
            ->where('i.id = :id')
            ->setParameter('id', $id);

        $result = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if ($result && isset($result[0])) {
            $flat = $result[0];
            $flat['patient_name'] = trim(($result['last_name'] ?? '') . ' ' . ($result['first_name'] ?? ''));

            if ($flat['issued_date'] instanceof \DateTimeInterface) {
                $flat['issued_date'] = $flat['issued_date']->format('Y-m-d H:i:s');
            }
            if ($flat['paid_date'] instanceof \DateTimeInterface) {
                $flat['paid_date'] = $flat['paid_date']->format('Y-m-d H:i:s');
            }
            if ($flat['created_at'] instanceof \DateTimeInterface) {
                $flat['created_at'] = $flat['created_at']->format('Y-m-d H:i:s');
            }
            if ($flat['updated_at'] instanceof \DateTimeInterface) {
                $flat['updated_at'] = $flat['updated_at']->format('Y-m-d H:i:s');
            }

            $flat['payments'] = $this->paymentRepository->findByInvoiceId($id);
            $flat['total_paid'] = array_sum(array_column($flat['payments'], 'amount'));
            $flat['remaining_amount'] = $flat['amount'] - $flat['total_paid'];
            return $flat;
        }

        return null;
    }

    public function update(int $id, array $data) : bool
    {
        $oldInvoice = $this->findById($id);
        if (! $oldInvoice) {
            return false;
        }

        /** @var Invoice|null $invoice */
        $invoice = $this->find($id);
        if (! $invoice) {
            return false;
        }

        $invoice->setPatientId((int)$data['patient_id']);

        if (array_key_exists('appointment_id', $data)) {
            $invoice->setAppointmentId(! empty($data['appointment_id']) ? (int)$data['appointment_id'] : null);
        }
        if (array_key_exists('medical_record_id', $data)) {
            $invoice->setMedicalRecordId(! empty($data['medical_record_id']) ? (int)$data['medical_record_id'] : null);
        }

        $invoice->setAmount((float)$data['amount']);
        $invoice->setStatus($data['status']);

        if (array_key_exists('notes', $data)) {
            $invoice->setNotes($data['notes']);
        }

        if ('paid' === $data['status'] && ! empty($data['paid_date'])) {
            try {
                $invoice->setPaidDate(new \DateTime($data['paid_date']));
            } catch (\Exception $e) {
                // ignore
            }
        } elseif ('paid' !== $data['status']) {
            $invoice->setPaidDate(null);
        }

        if (array_key_exists('type', $data)) {
            $invoice->setType($data['type'] ?? 'invoice');
        }

        try {
            $this->getEntityManager()->flush();
            $this->eventDispatcher->dispatch(new EntityChangedEvent('invoice', $id, 'update', $oldInvoice, $data));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function addPayment(
        int $invoiceId,
        float $amount,
        string $paymentMethod,
        ?string $transactionId = null,
        ?string $notes = null
    ) : bool {
        $em = $this->getEntityManager();
        $em->beginTransaction();

        try {
            $this->paymentRepository->create($invoiceId, $amount, $paymentMethod, $transactionId, $notes);

            $invoiceData = $this->findById($invoiceId);
            if (
                $invoiceData &&
                $invoiceData['remaining_amount'] <= 0.01 &&
                'paid' !== $invoiceData['status']
            ) {
                $updateData = array_merge($invoiceData, [
                    'status' => 'paid',
                    'paid_date' => date('Y-m-d H:i:s')
                ]);
                $this->update($invoiceId, $updateData);
            }

            $em->commit();
            return true;
        } catch (\Exception $e) {
            $em->rollback();
            return false;
        }
    }

    public function logFinancialTransaction(
        int $patientId,
        float $amount,
        string $transactionType,
        string $description,
        ?int $entityId = null
    ) : bool {
        return true;
    }

    public function sumTotalAmountByDate(string $date) : float
    {
        $startDate = new \DateTime($date . ' 00:00:00');
        $endDate = new \DateTime($date . ' 23:59:59');

        $paymentsSum = $this->paymentRepository->sumAmountByDateRange($startDate, $endDate);

        $invoicesQb = $this->createQueryBuilder('i')
            ->select('i.amount')
            ->where("i.status = 'paid'");

        $invoicesQb->andWhere(
            $invoicesQb->expr()->orX(
                $invoicesQb->expr()->andX(
                    $invoicesQb->expr()->isNotNull('i.paid_date'),
                    'i.paid_date >= :startDate',
                    'i.paid_date <= :endDate'
                ),
                $invoicesQb->expr()->andX(
                    $invoicesQb->expr()->isNull('i.paid_date'),
                    'i.issued_date >= :startDate',
                    'i.issued_date <= :endDate'
                )
            )
        );
        $invoicesQb->setParameter('startDate', $startDate);
        $invoicesQb->setParameter('endDate', $endDate);

        $sub = $this->paymentRepository->existsSubqueryForInvoice();

        $invoicesQb->andWhere($invoicesQb->expr()->not($invoicesQb->expr()->exists($sub->getDQL())));

        $invoices = $invoicesQb->getQuery()->getArrayResult();
        $invoicesSum = (float) array_sum(array_column($invoices, 'amount'));

        return $paymentsSum + $invoicesSum;
    }

    public function getDailyRevenueForPeriod(string $startDate, string $endDate) : array
    {
        $start = new \DateTime($startDate . ' 00:00:00');
        $end = new \DateTime($endDate . ' 23:59:59');

        $qb = $this->createQueryBuilder('i')
            ->select('i.amount', 'i.issued_date')
            ->where("i.status = 'paid'")
            ->andWhere('i.issued_date >= :start')
            ->andWhere('i.issued_date <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('i.issued_date', 'ASC');

        $invoices = $qb->getQuery()->getArrayResult();

        $daily = [];
        foreach ($invoices as $invoice) {
            /** @var \DateTimeInterface $dateObj */
            $dateObj = $invoice['issued_date'];
            $dateStr = $dateObj->format('Y-m-d');

            if (! isset($daily[$dateStr])) {
                $daily[$dateStr] = 0.0;
            }
            $daily[$dateStr] += (float)$invoice['amount'];
        }

        $result = [];
        foreach ($daily as $date => $total) {
            $result[] = [
                'date' => $date,
                'total_revenue' => $total
            ];
        }

        return $result;
    }

    public function sumRevenueForPeriod(string $startDate, string $endDate) : float
    {
        $start = new \DateTime($startDate . ' 00:00:00');
        $end = new \DateTime($endDate . ' 23:59:59');

        $qb = $this->createQueryBuilder('i')
            ->select('COALESCE(SUM(i.amount), 0)')
            ->where("i.status = 'paid'")
            ->andWhere('i.issued_date >= :start')
            ->andWhere('i.issued_date <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        return (float) $qb->getQuery()->getSingleScalarResult();
    }
}
