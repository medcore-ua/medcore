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

use App\Domain\Appointment\AppointmentRepository;
use App\Domain\Billing\InvoiceRepository;
use App\Domain\Billing\ServiceBundleRepository;
use App\Domain\Billing\ServiceCategoryRepository;
use App\Domain\Billing\ServiceRepository;
use App\Domain\Insurance\InsuranceService;
use App\Domain\MedicalRecord\MedicalRecordRepository;
use App\Domain\Patient\PatientRepository;
use App\Infrastructure\Export\ExcelExporter;
use App\Infrastructure\Export\PdfExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BillingController extends AbstractController
{
    private InvoiceRepository $invoiceRepository;
    private PatientRepository $patientRepository;
    private AppointmentRepository $appointmentRepository;
    private MedicalRecordRepository $medicalRecordRepository;
    private ServiceRepository $serviceRepository;
    private ServiceCategoryRepository $serviceCategoryRepository;
    private ServiceBundleRepository $serviceBundleRepository;
    private InsuranceService $insuranceService;
    private \App\Shared\Validation\Validator $validator;

    public function __construct(
        InvoiceRepository $invoiceRepository,
        PatientRepository $patientRepository,
        AppointmentRepository $appointmentRepository,
        MedicalRecordRepository $medicalRecordRepository,
        ServiceRepository $serviceRepository,
        ServiceCategoryRepository $serviceCategoryRepository,
        ServiceBundleRepository $serviceBundleRepository,
        InsuranceService $insuranceService,
        \App\Shared\Validation\Validator $validator
    ) {
        $this->invoiceRepository = $invoiceRepository;
        $this->patientRepository = $patientRepository;
        $this->appointmentRepository = $appointmentRepository;
        $this->medicalRecordRepository = $medicalRecordRepository;
        $this->serviceRepository = $serviceRepository;
        $this->serviceCategoryRepository = $serviceCategoryRepository;
        $this->serviceBundleRepository = $serviceBundleRepository;
        $this->insuranceService = $insuranceService;
        $this->validator = $validator;
    }

    #[Route('/billing', name: 'billing_index', methods: ['GET'])]
    public function index() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_VIEW');
        $searchTerm = $_GET['search'] ?? '';
        $invoices = $this->invoiceRepository->findAll($searchTerm);
        return $this->render('billing/index.html.twig', [
            'invoices' => $invoices,
            'searchTerm' => $searchTerm,
        ]);
    }

    // --- Service Management ---
    #[Route('/billing/services', name: 'billing_services_index', methods: ['GET'])]
    public function listServices() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');
        $services = $this->serviceRepository->findAll();
        return $this->render('billing/services/index.html.twig', ['services' => $services]);
    }

    #[Route('/billing/services/new', name: 'billing_services_new_get', methods: ['GET'])]
    public function createService() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');
        $categories = $this->serviceCategoryRepository->findAllCategories();
        $response = $this->render('billing/services/new.html.twig', [
            'categories' => $categories,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/billing/services/new', name: 'billing_services_new_post', methods: ['POST'])]
    public function storeService() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('billing_services_new_get');
        }

        $this->serviceRepository->save($_POST);
        $_SESSION['success_message'] = "Послугу успішно додано.";
        return $this->redirectToRoute('billing_services_index');
    }

    // --- Service Bundle Management ---
    #[Route('/billing/bundles', name: 'billing_bundles_index', methods: ['GET'])]
    public function listServiceBundles() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');
        $bundles = $this->serviceBundleRepository->findAll();
        return $this->render('billing/bundles/index.html.twig', ['bundles' => $bundles]);
    }

    #[Route('/billing/bundles/new', name: 'billing_bundles_new_get', methods: ['GET'])]
    public function createServiceBundle() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');
        $services = $this->serviceRepository->findAll();
        $response = $this->render('billing/bundles/new.html.twig', [
            'services' => $services,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/billing/bundles/new', name: 'billing_bundles_new_post', methods: ['POST'])]
    public function storeServiceBundle() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required'],
            'price' => ['required', 'numeric', 'min:0'],
            'services' => ['array'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('billing_bundles_new_get');
        }

        $this->serviceBundleRepository->save($_POST);
        $_SESSION['success_message'] = "Пакет послуг успішно додано.";
        return $this->redirectToRoute('billing_bundles_index');
    }

    #[Route('/billing/new', name: 'billing_new', methods: ['GET'])]
    public function create() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $patientId = $_GET['patient_id'] ?? null;

        $patients = $this->patientRepository->findAllActive();
        $appointments = $patientId
            ? $this->appointmentRepository->findByPatientId((int)$patientId)
            : $this->appointmentRepository->findAll();
        $medicalRecords = $patientId
            ? $this->medicalRecordRepository->findByPatientId((int)$patientId)
            : $this->medicalRecordRepository->findAll();

        $patientOptions = [];
        foreach ($patients as $patient) {
            $patientOptions[$patient['id']] = $patient['full_name'];
        }

        $appointmentOptions = [];
        foreach ($appointments as $appointment) {
            $appointmentOptions[$appointment['id']] = 'Запис #' . $appointment['id']
                . ' - ' . ($appointment['patient_name'] ?? 'Невідомий пацієнт') . ' (' . $appointment['start_time'] . ')';
        }

        $medicalRecordOptions = [];
        foreach ($medicalRecords as $record) {
            $medicalRecordOptions[$record['id']] = 'Запис #' . $record['id']
                . ' - ' . ($record['patient_name'] ?? 'Невідомий пацієнт') . ' (' . $record['visit_date'] . ')';
        }

        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        $errors = $_SESSION['errors'] ?? [];
        unset($_SESSION['errors']);

        return $this->render('billing/new.html.twig', [
            'patients' => $patientOptions,
            'appointments' => $appointmentOptions,
            'medical_records' => $medicalRecordOptions,
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    #[Route('/billing/new', name: 'billing_store', methods: ['POST'])]
    public function store() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'patient_id' => ['required', 'numeric'],
            'amount' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:pending,paid,cancelled'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('billing_new');
        }

        $invoiceId = $this->invoiceRepository->save($_POST);

        if ($invoiceId) {
            $patientId = (int)$_POST['patient_id'];
            $policies = $this->insuranceService->getPatientPolicies($patientId);
            $activePolicy = null;
            foreach ($policies as $policy) {
                if ($policy['is_active']) {
                    $activePolicy = $policy;
                    break;
                }
            }

            if ($activePolicy) {
                $amount = (float)$_POST['amount'];
                // Create a claim for the full amount
                $this->insuranceService->createClaim($invoiceId, $activePolicy['id'], $amount);

                // Update invoice with insurance and patient due amounts
                // For now, let's assume insurance covers 100%
                $this->invoiceRepository->updateInsuranceDue($invoiceId, $amount, 0.00);
            } else {
                // If no active policy, patient owes the full amount
                $amount = (float)$_POST['amount'];
                $this->invoiceRepository->updateInsuranceDue($invoiceId, 0.00, $amount);
            }
        }

        $_SESSION['success_message'] = "Рахунок успішно створено.";
        return $this->redirectToRoute('billing_index');
    }

    #[Route('/billing/show', name: 'billing_show', methods: ['GET'])]
    public function show() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_VIEW');

        $id = (int)($_GET['id'] ?? 0);
        $invoice = $this->invoiceRepository->findById($id);

        if (! $invoice) {
            return new Response("Рахунок не знайдено", 404);
        }

        $response = $this->render('billing/show.html.twig', [
            'invoice' => $invoice,
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['errors']);
        return $response;
    }

    #[Route('/billing/add-payment', name: 'billing_add_payment', methods: ['POST'])]
    public function addPayment() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $invoice = $this->invoiceRepository->findById($invoiceId);

        if (! $invoice) {
            return new Response("Рахунок не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('billing_show', ['id' => $invoiceId]);
        }

        $this->invoiceRepository->addPayment(
            $invoiceId,
            (float)$_POST['amount'],
            $_POST['payment_method'],
            $_POST['transaction_id'] ?? null,
            $_POST['notes'] ?? null
        );

        $_SESSION['success_message'] = "Оплата успішно додана.";
        return $this->redirectToRoute('billing_show', ['id' => $invoiceId]);
    }

    #[Route('/billing/edit', name: 'billing_edit', methods: ['GET'])]
    public function edit() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $invoice = $this->invoiceRepository->findById($id);

        if (! $invoice) {
            return new Response("Рахунок не знайдено", 404);
        }

        $patientId = $invoice['patient_id'];

        $patients = $this->patientRepository->findAllActive();
        $appointments = $this->appointmentRepository->findByPatientId(
            (int)$patientId
        );
        $medicalRecords = $this->medicalRecordRepository->findByPatientId(
            (int)$patientId
        );

        $patientOptions = [];
        foreach ($patients as $patient) {
            $patientOptions[$patient['id']] = $patient['full_name'];
        }

        $appointmentOptions = [];
        foreach ($appointments as $appointment) {
            $appointmentOptions[$appointment['id']] = 'Запис #' . $appointment['id'] . ' - '
                . ($appointment['patient_name'] ?? 'Невідомий пацієнт') . ' (' . $appointment['start_time'] . ')';
        }

        $medicalRecordOptions = [];
        foreach ($medicalRecords as $record) {
            $medicalRecordOptions[$record['id']] = 'Запис #' . $record['id'] . ' - '
                . ($record['patient_name'] ?? 'Невідомий пацієнт') . ' (' . $record['visit_date'] . ')';
        }

        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        $errors = $_SESSION['errors'] ?? [];
        unset($_SESSION['errors']);

        return $this->render('billing/edit.html.twig', [
            'invoice' => $invoice,
            'patients' => $patientOptions,
            'appointments' => $appointmentOptions,
            'medical_records' => $medicalRecordOptions,
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    #[Route('/billing/edit', name: 'billing_update', methods: ['POST'])]
    public function update() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $invoice = $this->invoiceRepository->findById($id);

        if (! $invoice) {
            return new Response("Рахунок не знайдено", 404);
        }

        // TODO: Add validation
        $validator = $this->validator;
        $validator->validate($_POST, [
            'amount' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:pending,paid,cancelled'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('billing_edit', ['id' => $id]);
        }

        $data = $_POST;
        $data['patient_id'] = $invoice['patient_id']; // Patient ID cannot be changed after creation
        $this->invoiceRepository->update($id, $data);
        $_SESSION['success_message'] = "Рахунок успішно оновлено.";
        return $this->redirectToRoute('billing_show', ['id' => $id]);
    }

    #[Route('/billing/export-csv', name: 'billing_export_csv', methods: ['GET'])]
    public function exportInvoicesToCsv() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_VIEW');

        // Fetch all invoices
        $invoices = $this->invoiceRepository->findAll();

        // Prepare data for CSV
        $headers = [
            'ID', 'Пацієнт', 'Сума', 'Статус', 'Дата виставлення', 'Дата оплати', 'Тип', 'Примітки'
        ];
        $exportData = [];
        foreach ($invoices as $invoice) {
            $exportData[] = [
                $invoice['id'],
                $invoice['patient_name'],
                $invoice['amount'],
                $invoice['status'],
                $invoice['issued_date'],
                $invoice['paid_date'],
                $invoice['type'],
                $invoice['notes'],
            ];
        }

        if (empty($exportData)) {
            $exportData[] = ['N/A', '', '', '', '', '', '', ''];
        }

        $exporter = new \App\Infrastructure\Export\CsvExporter($headers, $exportData);
        $csvContent = $exporter->generate();

        $response = new Response($csvContent);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="invoices_export.csv"');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    #[Route('/billing/export-pdf', name: 'billing_export_pdf', methods: ['GET'])]
    public function exportInvoicesToPdf() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_VIEW');

        $invoices = $this->invoiceRepository->findAll();

        $html = $this->renderView('billing/export_pdf.html.twig', ['invoices' => $invoices]);

        $pdfExporter = new PdfExporter();
        $pdfExporter->loadHtml($html);
        $pdfExporter->render();
        $pdfContent = $pdfExporter->output();

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="invoices_export.pdf"');

        return $response;
    }

    #[Route('/billing/export-excel', name: 'billing_export_excel', methods: ['GET'])]
    public function exportInvoicesToExcel() : Response
    {
        $this->denyAccessUnlessGranted('BILLING_VIEW');

        $invoices = $this->invoiceRepository->findAll();

        $headers = [
            'ID', 'Пацієнт', 'Сума', 'Статус', 'Дата виставлення'
        ];
        $data = [];
        foreach ($invoices as $invoice) {
            $data[] = [
                $invoice['id'],
                $invoice['patient_name'],
                $invoice['amount'],
                $invoice['status'],
                $invoice['issued_date']
            ];
        }

        if (empty($data)) {
            $data[] = ['N/A', '', '', '', ''];
        }

        $excelExporter = new ExcelExporter();
        $excelContent = $excelExporter->generate($headers, $data);

        $response = new Response($excelContent);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="invoices_export.xlsx"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }
}
