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

namespace App\Http\Billing;

use App\Domain\Billing\ContractRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ContractController extends AbstractController
{
    private ContractRepository $contractRepository;
    private \App\Shared\Validation\Validator $validator;

    public function __construct(
        ContractRepository $contractRepository,
        \App\Shared\Validation\Validator $validator
    ) {
        $this->contractRepository = $contractRepository;
        $this->validator = $validator;
    }

    #[Route('/billing/contracts', name: 'billing_contracts_index', methods: ['GET'])]
    public function index() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');
        $contracts = $this->contractRepository->findAll();
        return $this->render('billing/contracts/index.html.twig', ['contracts' => $contracts]);
    }

    #[Route('/billing/contracts/new', name: 'billing_contracts_new', methods: ['GET'])]
    public function create() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');
        $response = $this->render('billing/contracts/new.html.twig', [
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/billing/contracts/new', name: 'billing_contracts_store', methods: ['POST'])]
    public function store() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'title' => ['required'],
            'start_date' => ['required', 'date'],
            'status' => ['required', 'in:active,expired,terminated'],
        ]);

        if (isset($_POST['end_date']) && '' === $_POST['end_date']) {
            unset($_POST['end_date']);
        }

        // Normalize optional end_date: store null instead of empty string
        if (isset($_POST['end_date']) && '' === $_POST['end_date']) {
            unset($_POST['end_date']);
        }

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('billing_contracts_new');
        }

        // Handle file upload for contract document
        $filePath = null;
        if (isset($_FILES['contract_file']) && UPLOAD_ERR_OK === $_FILES['contract_file']['error']) {
            $uploadDir = dirname(__DIR__, 3) . '/uploads/contracts/';
            if (! is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $filename = uniqid('contract_', true) . '_' . basename($_FILES['contract_file']['name']);
            $targetPath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['contract_file']['tmp_name'], $targetPath)) {
                $filePath = 'uploads/contracts/' . $filename; // Store relative path
            }
        }

        $data = $_POST;
        $data['file_path'] = $filePath;

        $this->contractRepository->save($data);
        $_SESSION['success_message'] = "Контракт успішно створено.";
        return $this->redirectToRoute('billing_contracts_index');
    }

    #[Route('/billing/contracts/show', name: 'billing_contracts_show', methods: ['GET'])]
    public function show() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $contract = $this->contractRepository->findById($id);

        if (! $contract) {
            return new Response("Контракт не знайдено", 404);
        }

        return $this->render('billing/contracts/show.html.twig', ['contract' => $contract]);
    }

    #[Route('/billing/contracts/edit', name: 'billing_contracts_edit', methods: ['GET'])]
    public function edit() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $contract = $this->contractRepository->findById($id);

        if (! $contract) {
            return new Response("Контракт не знайдено", 404);
        }

        $response = $this->render('billing/contracts/edit.html.twig', [
            'contract' => $contract,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/billing/contracts/edit', name: 'billing_contracts_update', methods: ['POST'])]
    public function update() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $contract = $this->contractRepository->findById($id);

        if (! $contract) {
            return new Response("Контракт не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'title' => ['required'],
            'start_date' => ['required', 'date'],
            'status' => ['required', 'in:active,expired,terminated'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('billing_contracts_edit', ['id' => $id]);
        }

        // Handle file upload for contract document (if new file is uploaded)
        $filePath = $contract['file_path']; // Keep existing path by default
        if (isset($_FILES['contract_file']) && UPLOAD_ERR_OK === $_FILES['contract_file']['error']) {
            $uploadDir = dirname(__DIR__, 3) . '/uploads/contracts/';
            if (! is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $filename = uniqid('contract_', true) . '_' . basename($_FILES['contract_file']['name']);
            $targetPath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['contract_file']['tmp_name'], $targetPath)) {
                $filePath = 'uploads/contracts/' . $filename; // Store relative path
                // Optionally, delete old file
            }
        }

        $data = $_POST;
        $data['file_path'] = $filePath;

        $this->contractRepository->update($id, $data);
        $_SESSION['success_message'] = "Контракт успішно оновлено.";
        return $this->redirectToRoute('billing_contracts_show', ['id' => $id]);
    }

    #[Route('/billing/contracts/delete', name: 'billing_contracts_delete', methods: ['POST'])]
    public function delete() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $contract = $this->contractRepository->findById($id);

        if (! $contract) {
            return new Response("Контракт не знайдено", 404);
        }

        // Optionally, delete the physical file
        if ($contract['file_path'] && file_exists(dirname(__DIR__, 3) . '/' . $contract['file_path'])) {
            unlink(dirname(__DIR__, 3) . '/' . $contract['file_path']);
        }

        $this->contractRepository->delete($id);
        $_SESSION['success_message'] = "Контракт успішно видалено.";
        return $this->redirectToRoute('billing_contracts_index');
    }

    #[Route('/billing/contracts/{id}/download', name: 'billing_contracts_download', methods: ['GET'])]
    public function downloadFile(int $id) : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $contract = $this->contractRepository->findById($id);

        if (! $contract || ! $contract['file_path'] || ! file_exists(dirname(__DIR__, 3) . '/' . $contract['file_path'])) {
            return new Response("Файл контракту не знайдено", 404);
        }

        $filePath = dirname(__DIR__, 3) . '/' . $contract['file_path'];
        $filename = basename($contract['file_path']);
        $mimeType = mime_content_type($filePath);

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }
}
