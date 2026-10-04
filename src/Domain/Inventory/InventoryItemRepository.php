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

namespace App\Domain\Inventory;

use App\Domain\Billing\InvoiceRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InventoryItemRepository extends ServiceEntityRepository
{
    private InvoiceRepository $invoiceRepository;

    public function __construct(ManagerRegistry $registry, InvoiceRepository $invoiceRepository)
    {
        parent::__construct($registry, InventoryItem::class);
        $this->invoiceRepository = $invoiceRepository;
    }

    public function findAll(string $searchTerm = '') : array
    {
        $qb = $this->createQueryBuilder('i');

        if (! empty($searchTerm)) {
            $qb->andWhere('i.name LIKE :term OR i.inn LIKE :term OR i.supplier LIKE :term OR i.batch_number LIKE :term')
               ->setParameter('term', '%' . $searchTerm . '%');
        }

        $qb->orderBy('i.name', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findItemsBelowMinStock() : array
    {
        return $this->createQueryBuilder('i')
            ->where('i.quantity < i.min_stock_level')
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function countItemsBelowMinStock() : int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.quantity < i.min_stock_level')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findItemsAboveMaxStock() : array
    {
        return $this->createQueryBuilder('i')
            ->where('i.quantity > i.max_stock_level')
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function save(array $data, ?int $userId = null) : bool
    {
        $em = $this->getEntityManager();
        $em->beginTransaction();
        try {
            $item = new InventoryItem();
            $item->setName($data['name']);
            if (isset($data['description'])) {
                $item->setDescription($data['description']);
            }
            if (isset($data['inn'])) {
                $item->setInn($data['inn']);
            }
            if (isset($data['batch_number'])) {
                $item->setBatchNumber($data['batch_number']);
            }
            if (! empty($data['expiry_date'])) {
                $item->setExpiryDate(new \DateTime($data['expiry_date']));
            }
            if (isset($data['supplier'])) {
                $item->setSupplier($data['supplier']);
            }
            $item->setCost((string)($data['cost'] ?? 0.00));
            $item->setQuantity((int)($data['quantity'] ?? 0));
            $item->setMinStockLevel((int)($data['min_stock_level'] ?? 0));
            $item->setMaxStockLevel((int)($data['max_stock_level'] ?? 0));
            if (isset($data['location'])) {
                $item->setLocation($data['location']);
            }

            $em->persist($item);
            $em->flush();

            $itemId = $item->getId();
            if (($data['quantity'] ?? 0) > 0) {
                $this->logMovement(
                    $itemId,
                    $userId,
                    'in',
                    $data['quantity'],
                    $data['quantity'],
                    'Початковий запас',
                    (float)($data['cost'] ?? 0.00)
                );
            }
            $em->commit();
            return true;
        } catch (\Exception $e) {
            $em->rollBack();
            return false;
        }
    }

    public function findById(int $id) : ?array
    {
        $result = $this->createQueryBuilder('i')
            ->where('i.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getArrayResult();
        return $result ? $result[0] : null;
    }

    public function update(int $id, array $data, ?int $userId = null) : bool
    {
        $em = $this->getEntityManager();
        $em->beginTransaction();
        try {
            $item = $this->find($id);
            if (! $item) {
                $em->rollBack();
                return false;
            }
            $oldQuantity = $item->getQuantity();
            $newQuantity = $data['quantity'] ?? $oldQuantity;
            $oldCost = (float)$item->getCost();
            $newCost = (float)($data['cost'] ?? $oldCost);

            $item->setName($data['name']);
            if (array_key_exists('description', $data)) {
                $item->setDescription($data['description']);
            }
            if (array_key_exists('inn', $data)) {
                $item->setInn($data['inn']);
            }
            if (array_key_exists('batch_number', $data)) {
                $item->setBatchNumber($data['batch_number']);
            }
            if (array_key_exists('expiry_date', $data)) {
                $item->setExpiryDate($data['expiry_date'] ? new \DateTime($data['expiry_date']) : null);
            }
            if (array_key_exists('supplier', $data)) {
                $item->setSupplier($data['supplier']);
            }
            $item->setCost((string)$newCost);
            $item->setQuantity((int)$newQuantity);
            if (array_key_exists('min_stock_level', $data)) {
                $item->setMinStockLevel((int)$data['min_stock_level']);
            }
            if (array_key_exists('max_stock_level', $data)) {
                $item->setMaxStockLevel((int)$data['max_stock_level']);
            }
            if (array_key_exists('location', $data)) {
                $item->setLocation($data['location']);
            }

            $em->flush();

            if ($newQuantity !== $oldQuantity) {
                $movementType = $newQuantity > $oldQuantity ? 'in' : 'out';
                $quantityChange = abs($newQuantity - $oldQuantity);
                $reason = $data['movement_reason'] ?? 'Оновлення позиції';
                $this->logMovement(
                    $id,
                    $userId,
                    $movementType,
                    $quantityChange,
                    $newQuantity,
                    $reason,
                    $newCost
                );
            }
            $em->commit();
            return true;
        } catch (\Exception $e) {
            $em->rollBack();
            return false;
        }
    }

    private function logMovement(
        int $itemId,
        ?int $userId,
        string $movementType,
        int $quantityChange,
        int $newQuantity,
        string $reason,
        float $itemCost
    ) : bool {
        $em = $this->getEntityManager();
        $qb = $em->getConnection()->createQueryBuilder();

        $success = $qb->insert('inventory_movements')
            ->values([
                'inventory_item_id' => ':inventory_item_id',
                'user_id' => ':user_id',
                'movement_type' => ':movement_type',
                'quantity_change' => ':quantity_change',
                'new_quantity' => ':new_quantity',
                'reason' => ':reason',
            ])
            ->setParameters([
                'inventory_item_id' => $itemId,
                'user_id' => $userId,
                'movement_type' => $movementType,
                'quantity_change' => $quantityChange,
                'new_quantity' => $newQuantity,
                'reason' => $reason,
            ])
            ->executeStatement();

        if ($success > 0) {
            $amount = $quantityChange * $itemCost;
            $transactionType = 'in' === $movementType ? 'inventory_cost' : 'inventory_revenue';
            if ('out' === $movementType) {
                $amount = -$amount;
            }

            $itemDetails = $this->findById($itemId);
            $patientId = 0;
            if (isset($_SESSION['current_patient_id'])) {
                $patientId = $_SESSION['current_patient_id'];
            }

            $this->invoiceRepository->logFinancialTransaction(
                $patientId,
                $amount,
                $transactionType,
                sprintf(
                    "Рух складу: %s %d одиниць '%s'. Причина: %s",
                    'in' === $movementType ? 'Прихід' : 'Вибуття',
                    $quantityChange,
                    $itemDetails['name'] ?? 'Unknown',
                    $reason
                ),
                $itemId
            );
        }
        return $success > 0;
    }

    public function getMovementHistory(int $itemId) : array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $qb->select('im.*', "CONCAT(u.last_name, ' ', u.first_name) as user_name")
           ->from('inventory_movements', 'im')
           ->leftJoin('im', 'users', 'u', 'im.user_id = u.id')
           ->where('im.inventory_item_id = :inventory_item_id')
           ->setParameter('inventory_item_id', $itemId)
           ->orderBy('im.created_at', 'DESC');

        return $qb->fetchAllAssociative();
    }

    public function findByName(string $name) : ?array
    {
        $result = $this->createQueryBuilder('i')
            ->where('i.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getArrayResult();
        return $result ? $result[0] : null;
    }

    public function decreaseQuantity(int $itemId, int $quantity, ?int $userId = null, string $reason = 'Виконання рецепту') : bool
    {
        $em = $this->getEntityManager();
        $em->beginTransaction();
        try {
            $item = $this->find($itemId);
            if (! $item || $item->getQuantity() < $quantity) {
                $em->rollBack();
                return false;
            }

            $newQuantity = $item->getQuantity() - $quantity;
            $item->setQuantity($newQuantity);
            $em->flush();

            $this->logMovement($itemId, $userId, 'out', $quantity, $newQuantity, $reason, (float)$item->getCost());

            $em->commit();
            return true;
        } catch (\Exception $e) {
            $em->rollBack();
            return false;
        }
    }
}
