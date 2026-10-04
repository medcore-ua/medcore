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

namespace App\Http\LabOrder;

use App\Domain\LabOrder\LabImportService;
use App\Domain\LabOrder\LabOrderRepository;
use App\Domain\MedicalRecord\MedicalRecordRepository;
use App\Domain\User\UserRepository;
use App\Shared\Service\NotificationService;
use App\Shared\Service\QrCodeGenerator;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LabOrderController extends AbstractController
{
    private MedicalRecordRepository $medicalRecordRepository;
    private LabOrderRepository $labOrderRepository;
    private UserRepository $userRepository;
    private NotificationService $notificationService;
    private QrCodeGenerator $qrCodeGenerator;
    private LabImportService $labImportService;
    private Validator $validator;

    public function __construct(
        MedicalRecordRepository $medicalRecordRepository,
        LabOrderRepository $labOrderRepository,
        UserRepository $userRepository,
        NotificationService $notificationService,
        QrCodeGenerator $qrCodeGenerator,
        LabImportService $labImportService,
        Validator $validator
    ) {
        $this->medicalRecordRepository = $medicalRecordRepository;
        $this->labOrderRepository = $labOrderRepository;
        $this->userRepository = $userRepository;
        $this->notificationService = $notificationService;
        $this->qrCodeGenerator = $qrCodeGenerator;
        $this->labImportService = $labImportService;
        $this->validator = $validator;
    }

    #[Route('/lab-orders/new', name: 'lab_orders_new_get', methods: ['GET'])]
    public function create() : Response
    {
        $this->denyAccessUnlessGranted('LAB_ORDER_CREATE');

        $recordId = (int)($_GET['record_id'] ?? 0);
        $medicalRecord = $this->medicalRecordRepository->findById($recordId);

        if (! $medicalRecord) {
            return new Response("Медичний запис не знайдено", 404);
        }

        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        $errors = $_SESSION['errors'] ?? [];
        unset($_SESSION['errors']);

        return $this->render('lab_order/new.html.twig', [
            'medical_record' => $medicalRecord,
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    #[Route('/lab-orders/new', name: 'lab_orders_new_post', methods: ['POST'])]
    public function store() : Response
    {
        $this->denyAccessUnlessGranted('LAB_ORDER_CREATE');

        $recordId = (int)($_GET['record_id'] ?? 0);
        $medicalRecord = $this->medicalRecordRepository->findById($recordId);

        if (! $medicalRecord) {
            return new Response("Медичний запис не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'order_code' => ['required'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('lab_orders_new_get', ['record_id' => $recordId]);
        }

        $data = $_POST;
        $data['patient_id'] = $medicalRecord['patient_id'];
        $data['doctor_id'] = $medicalRecord['doctor_id'];
        $data['medical_record_id'] = $recordId;

        $labOrderId = $this->labOrderRepository->save($data);

        if ($labOrderId) {
            $qrCodeData = $_ENV['APP_BASE_URL'] . '/lab-orders/show?id=' . $labOrderId;
            $qrCodeHash = hash('sha256', $qrCodeData);
            $updateSuccess = $this->labOrderRepository->updateQrCodeHash($labOrderId, $qrCodeHash);
            if (! $updateSuccess) {
                error_log("Failed to update QR code hash for lab order ID: " . $labOrderId);
            }
        }

        $doctor = $this->userRepository->findById($medicalRecord['doctor_id']);
        if ($doctor) {
            $message = sprintf(
                'Нове лабораторне замовлення "%s" створено для медичного запису #%d.',
                $data['order_code'],
                $recordId
            );
            $this->notificationService->createNotification($doctor['id'], $message);
        }

        $_SESSION['success_message'] = "Лабораторне замовлення успішно створено.";
        return $this->redirectToRoute('medical_records_show', ['id' => $recordId]);
    }

    #[Route('/lab-orders/show', name: 'lab_orders_show_get', methods: ['GET'])]
    public function show() : Response
    {
        $id = (int)($_GET['id'] ?? 0);
        $order = $this->labOrderRepository->findById($id);

        if (! $order) {
            return new Response("Лабораторне замовлення не знайдено", 404);
        }

        $this->denyAccessUnlessGranted('LAB_ORDER_VIEW', $id);

        $qrCodeData = $_ENV['APP_BASE_URL'] . '/lab-orders/show?id=' . $id;
        $qrCodeImage = $this->qrCodeGenerator->generateQrCodeAsBase64($qrCodeData);

        return $this->render('lab_order/show.html.twig', [
            'order' => $order,
            'qrCodeImage' => $qrCodeImage,
        ]);
    }

    #[Route('/lab-orders/edit', name: 'lab_orders_edit_get', methods: ['GET'])]
    public function edit() : Response
    {
        $id = (int)($_GET['id'] ?? 0);
        $order = $this->labOrderRepository->findById($id);

        if (! $order) {
            return new Response("Лабораторне замовлення не знайдено", 404);
        }

        $this->denyAccessUnlessGranted('LAB_ORDER_EDIT', $id);

        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        $errors = $_SESSION['errors'] ?? [];
        unset($_SESSION['errors']);

        return $this->render('lab_order/edit.html.twig', [
            'order' => $order,
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    #[Route('/lab-orders/edit', name: 'lab_orders_edit_post', methods: ['POST'])]
    public function update() : Response
    {
        $id = (int)($_POST['id'] ?? 0);
        $order = $this->labOrderRepository->findById($id);

        if (! $order) {
            return new Response("Лабораторне замовлення не знайдено", 404);
        }

        $this->denyAccessUnlessGranted('LAB_ORDER_EDIT', $id);

        $validator = $this->validator;
        $validator->validate($_POST, [
            'order_code' => ['required'],
            'status' => ['required', 'in:ordered,in_progress,completed,cancelled'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('lab_orders_edit_get', ['id' => $id]);
        }

        $this->labOrderRepository->update($id, $_POST);
        $_SESSION['success_message'] = "Лабораторне замовлення успішно оновлено.";
        return $this->redirectToRoute('lab_orders_show_get', ['id' => $id]);
    }

    #[Route('/lab-orders/import', name: 'lab_orders_import', methods: ['GET'])]
    public function import() : Response
    {
        $this->denyAccessUnlessGranted('LAB_ORDER_EDIT_ANY');

        $response = $this->render('lab_order/import.html.twig', [
            'errors' => $_SESSION['errors'] ?? [],
            'success_message' => $_SESSION['success_message'] ?? null,
        ]);
        unset($_SESSION['errors'], $_SESSION['success_message']);
        return $response;
    }

    #[Route('/lab-orders/import', name: 'lab_orders_import_post', methods: ['POST'])]
    public function processImport() : Response
    {
        $this->denyAccessUnlessGranted('LAB_ORDER_EDIT_ANY');

        if (empty($_FILES['hl7_dicom_file'])) {
            $_SESSION['errors']['file'] = 'Будь ласка, виберіть файл для завантаження.';
            return $this->redirectToRoute('lab_orders_import');
        }

        $file = $_FILES['hl7_dicom_file'];

        if (UPLOAD_ERR_OK !== $file['error']) {
            $_SESSION['errors']['file'] = 'Помилка завантаження файлу: ' . $file['error'];
            return $this->redirectToRoute('lab_orders_import');
        }

        $tempDir = dirname(__DIR__, 3) . '/uploads/temp/';
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $tempFilename = uniqid('hl7_dicom_temp_', true) . '_' . basename($file['name']);
        $tempPath = $tempDir . $tempFilename;

        if (! move_uploaded_file($file['tmp_name'], $tempPath)) {
            $_SESSION['errors']['file'] = 'Не вдалося зберегти завантажений файл для обробки.';
            return $this->redirectToRoute('lab_orders_import');
        }

        try {
            $parsedData = $this->labImportService->validateStructural($tempPath, $file['type']);
            $_SESSION['hl7_dicom_parsed_data'] = $parsedData;
            $_SESSION['hl7_dicom_temp_path'] = $tempPath;
            $_SESSION['success_message'] = 'Файл успішно завантажено та пройшов структурну валідацію. '
                                           . 'Будь ласка, перегляньте дані перед імпортом.';
            return $this->redirectToRoute('lab_orders_import_confirm');
        } catch (\Exception $e) {
            unlink($tempPath);
            $_SESSION['errors']['file'] = 'Помилка структурної валідації: ' . $e->getMessage();
            return $this->redirectToRoute('lab_orders_import');
        }
    }

    #[Route('/lab-orders/import/confirm', name: 'lab_orders_import_confirm', methods: ['GET'])]
    public function confirmImport() : Response
    {
        $this->denyAccessUnlessGranted('LAB_ORDER_EDIT_ANY');

        if (empty($_SESSION['hl7_dicom_parsed_data'])) {
            $_SESSION['errors']['import'] = 'Немає даних для підтвердження імпорту.';
            return $this->redirectToRoute('lab_orders_import');
        }

        $response = $this->render('lab_order/confirm_import.html.twig', [
            'parsedData' => $_SESSION['hl7_dicom_parsed_data'],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['errors']);
        return $response;
    }

    #[Route('/lab-orders/import/finalize', name: 'lab_orders_import_finalize', methods: ['POST'])]
    public function finalizeImport() : Response
    {
        $this->denyAccessUnlessGranted('LAB_ORDER_EDIT_ANY');

        if (empty($_SESSION['hl7_dicom_parsed_data']) || empty($_SESSION['hl7_dicom_temp_path'])) {
            $_SESSION['errors']['import'] = 'Немає даних для фіналізації імпорту.';
            return $this->redirectToRoute('lab_orders_import');
        }

        $parsedData = $_SESSION['hl7_dicom_parsed_data'];
        $tempPath = $_SESSION['hl7_dicom_temp_path'];

        try {
            $validatedData = $this->labImportService->validateLogical($parsedData);
            $orderId = $this->labImportService->importLabOrder($validatedData);

            unset($_SESSION['hl7_dicom_parsed_data']);
            unset($_SESSION['hl7_dicom_temp_path']);
            unlink($tempPath);

            $_SESSION['success_message'] = 'Лабораторне замовлення успішно імпортовано (ID: ' . $orderId . ').';
            return $this->redirectToRoute('lab_orders_show_get', ['id' => $orderId]);
        } catch (\Exception $e) {
            $_SESSION['errors']['import'] = 'Помилка логічної валідації або імпорту: ' . $e->getMessage();
            return $this->redirectToRoute('lab_orders_import_confirm');
        }
    }
}
