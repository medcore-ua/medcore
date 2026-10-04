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

namespace App\Http\Inventory;

use App\Domain\Inventory\InventoryItemRepository;
use App\Domain\User\User;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class InventoryController extends AbstractController
{
    private InventoryItemRepository $inventoryItemRepository;
    private Validator $validator;

    public function __construct(InventoryItemRepository $inventoryItemRepository, Validator $validator)
    {
        $this->inventoryItemRepository = $inventoryItemRepository;
        $this->validator = $validator;
    }

    #[Route('/inventory', name: 'inventory_index', methods: ['GET'])]
    public function index() : Response
    {
        $this->denyAccessUnlessGranted('INVENTORY_MANAGE');
        $searchTerm = $_GET['search'] ?? '';
        $items = $this->inventoryItemRepository->findAll($searchTerm);
        $lowStockItems = $this->inventoryItemRepository->findItemsBelowMinStock();
        $overStockedItems = $this->inventoryItemRepository->findItemsAboveMaxStock();

        return $this->render('inventory/index.html.twig', [
            'items' => $items,
            'lowStockItems' => $lowStockItems,
            'overStockedItems' => $overStockedItems,
            'searchTerm' => $searchTerm,
        ]);
    }

    #[Route('/inventory/new', name: 'inventory_new_create', methods: ['GET'])]
    public function create() : Response
    {
        $this->denyAccessUnlessGranted('INVENTORY_MANAGE');
        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        $errors = $_SESSION['errors'] ?? [];
        unset($_SESSION['errors']);

        return $this->render('inventory/new.html.twig', [
            'old' => $old,
            'errors' => $errors,
            'min_stock_level' => $old['min_stock_level'] ?? 0,
            'max_stock_level' => $old['max_stock_level'] ?? 0,
        ]);
    }

    #[Route('/inventory/new', name: 'inventory_new_store', methods: ['POST'])]
    public function store(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('INVENTORY_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'cost' => ['numeric', 'min:0'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('inventory_new_create');
        }

        $this->inventoryItemRepository->save($_POST, $user->getId());
        $_SESSION['success_message'] = "Позицію складу успішно додано.";
        return $this->redirectToRoute('inventory_index');
    }

    #[Route('/inventory/show', name: 'inventory_show_show', methods: ['GET'])]
    public function show() : Response
    {
        $this->denyAccessUnlessGranted('INVENTORY_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $item = $this->inventoryItemRepository->findById($id);

        if (! $item) {
            return new Response("Позицію складу не знайдено", 404);
        }

        $movementHistory = $this->inventoryItemRepository->getMovementHistory($id);

        return $this->render('inventory/show.html.twig', [
            'item' => $item,
            'movementHistory' => $movementHistory,
        ]);
    }

    #[Route('/inventory/edit', name: 'inventory_edit_edit', methods: ['GET'])]
    public function edit() : Response
    {
        $this->denyAccessUnlessGranted('INVENTORY_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $item = $this->inventoryItemRepository->findById($id);

        if (! $item) {
            return new Response("Позицію складу не знайдено", 404);
        }

        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        $errors = $_SESSION['errors'] ?? [];
        unset($_SESSION['errors']);

        return $this->render('inventory/edit.html.twig', [
            'item' => $item,
            'old' => $old,
            'errors' => $errors,
            'min_stock_level' => $old['min_stock_level'] ?? $item['min_stock_level'],
            'max_stock_level' => $old['max_stock_level'] ?? $item['max_stock_level'],
        ]);
    }

    #[Route('/inventory/edit', name: 'inventory_edit_update', methods: ['POST'])]
    public function update(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('INVENTORY_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $item = $this->inventoryItemRepository->findById($id);

        if (! $item) {
            return new Response("Позицію складу не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'cost' => ['numeric', 'min:0'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('inventory_edit_edit', ['id' => $id]);
        }

        $this->inventoryItemRepository->update($id, $_POST, $user->getId());
        $_SESSION['success_message'] = "Позицію складу успішно оновлено.";
        return $this->redirectToRoute('inventory_show_show', ['id' => $id]);
    }
}
