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

namespace App\Http\MedicalRecord;

use App\Domain\Appointment\AppointmentRepository;
use App\Domain\ClinicalReference\IcdCodeRepository;
use App\Domain\ClinicalReference\InterventionCodeRepository;
use App\Domain\LabOrder\LabOrderRepository;
use App\Domain\MedicalRecord\MedicalRecordRepository;
use App\Domain\User\User;
use App\Shared\Service\AttachmentService;
use App\Shared\Service\AuditLogger;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MedicalRecordController extends AbstractController
{
    private MedicalRecordRepository $medicalRecordRepository;
    private AppointmentRepository $appointmentRepository;
    private LabOrderRepository $labOrderRepository;
    private IcdCodeRepository $icdCodeRepository;
    private InterventionCodeRepository $interventionCodeRepository;
    private AttachmentService $attachmentService;
    private AuditLogger $auditLogger;
    private Validator $validator;

    public function __construct(
        MedicalRecordRepository $medicalRecordRepository,
        AppointmentRepository $appointmentRepository,
        LabOrderRepository $labOrderRepository,
        IcdCodeRepository $icdCodeRepository,
        InterventionCodeRepository $interventionCodeRepository,
        AttachmentService $attachmentService,
        AuditLogger $auditLogger,
        Validator $validator
    ) {
        $this->medicalRecordRepository = $medicalRecordRepository;
        $this->appointmentRepository = $appointmentRepository;
        $this->labOrderRepository = $labOrderRepository;
        $this->icdCodeRepository = $icdCodeRepository;
        $this->interventionCodeRepository = $interventionCodeRepository;
        $this->attachmentService = $attachmentService;
        $this->auditLogger = $auditLogger;
        $this->validator = $validator;
    }

    #[Route('/medical-records/new', name: 'medical_records_new_get', methods: ['GET'])]
    public function create() : Response
    {
        $this->denyAccessUnlessGranted('MEDICAL_RECORD_CREATE');

        $appointmentId = (int)($_GET['appointment_id'] ?? 0);
        $appointment = $this->appointmentRepository->findById($appointmentId);

        if (! $appointment) {
            return new Response("Запис не знайдено", 404);
        }

        $response = $this->render('medical_record/create.html.twig', [
            'appointment' => $appointment,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/medical-records', name: 'medical_records_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('MEDICAL_RECORD_VIEW');
        $searchTerm = $_GET['search'] ?? '';
        $records = [];

        if ($this->isGranted('MEDICAL_RECORD_VIEW_ANY')) {
            $records = $this->medicalRecordRepository->findAll($searchTerm);
        } elseif ($user && $user->getId()) {
            $records = $this->medicalRecordRepository->findByDoctorId($user->getId(), $searchTerm);
        }

        return $this->render('medical_record/index.html.twig', [
            'records' => $records,
            'searchTerm' => $searchTerm,
        ]);
    }

    #[Route('/medical-records/new', name: 'medical_records_new_post', methods: ['POST'])]
    public function store(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('MEDICAL_RECORD_CREATE');

        $appointmentId = (int)($_GET['appointment_id'] ?? 0);
        $appointment = $this->appointmentRepository->findById($appointmentId);

        if (! $appointment) {
            return new Response("Запис не знайдено", 404);
        }

        if (! empty($_POST['visit_date'])) {
            try {
                $dt = new \DateTime($_POST['visit_date']);
                $_POST['visit_date'] = $dt->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
            }
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'diagnosis_code' => ['required'],
            'visit_date' => ['required', 'datetime'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('medical_records_new_get', ['appointment_id' => $appointmentId]);
        }

        $data = $_POST;
        $data['patient_id'] = $appointment['patient_id'];
        $data['appointment_id'] = $appointmentId;
        $data['doctor_id'] = $appointment['doctor_id'];

        $medicalRecordId = $this->medicalRecordRepository->save($data);

        if ($medicalRecordId && ! empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if (UPLOAD_ERR_OK === $_FILES['attachments']['error'][$key]) {
                    $fileData = [
                        'name' => $name,
                        'type' => $_FILES['attachments']['type'][$key],
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$key],
                        'error' => $_FILES['attachments']['error'][$key],
                        'size' => $_FILES['attachments']['size'][$key],
                    ];
                    $this->attachmentService->uploadAttachment(
                        $fileData,
                        'medical_record',
                        $medicalRecordId,
                        $user->getId()
                    );
                }
            }
        }

        $this->appointmentRepository->updateStatus($appointmentId, 'completed');

        return $this->redirectToRoute('patient_show', ['id' => $appointment['patient_id']]);
    }

    #[Route('/medical-records/show', name: 'medical_records_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user) : Response
    {
        $id = (int)($_GET['id'] ?? 0);
        $this->denyAccessUnlessGranted('MEDICAL_RECORD_VIEW', $id);

        $record = $this->medicalRecordRepository->findById($id);

        if (! $record) {
            return new Response("Медичний запис не знайдено", 404);
        }

        $this->auditLogger->log(
            'medical_record',
            $id,
            'view',
            null,
            null,
            $user->getId()
        );

        $labOrders = $this->labOrderRepository->findByMedicalRecordId($id);
        $attachments = $this->attachmentService->getAttachmentsForEntity('medical_record', $id);

        return $this->render('medical_record/show.html.twig', [
            'record' => $record,
            'lab_orders' => $labOrders,
            'attachments' => $attachments,
        ]);
    }

    #[Route('/medical-records/icd-codes', name: 'medical_records_icd_codes', methods: ['GET'])]
    public function getIcdCodes() : JsonResponse
    {
        $this->denyAccessUnlessGranted('CLINICAL_REFERENCE_MANAGE');

        $searchTerm = $_GET['search'] ?? '';
        $codes = $this->icdCodeRepository->searchByCodeOrDescription($searchTerm);

        return new JsonResponse($codes);
    }

    #[Route('/medical-records/intervention-codes', name: 'medical_records_intervention_codes', methods: ['GET'])]
    public function getInterventionCodes() : JsonResponse
    {
        $this->denyAccessUnlessGranted('CLINICAL_REFERENCE_MANAGE');

        $searchTerm = $_GET['search'] ?? '';
        $codes = $this->interventionCodeRepository->searchByCodeOrDescription($searchTerm);

        return new JsonResponse($codes);
    }

    #[Route('/medical-records/edit', name: 'medical_records_edit_get', methods: ['GET'])]
    public function edit() : Response
    {
        $id = (int)($_GET['id'] ?? 0);
        $this->denyAccessUnlessGranted('MEDICAL_RECORD_EDIT', $id);

        $record = $this->medicalRecordRepository->findById($id);

        if (! $record) {
            return new Response("Медичний запис не знайдено", 404);
        }

        $response = $this->render('medical_record/edit.html.twig', [
            'record' => $record,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/medical-records/edit', name: 'medical_records_edit_post', methods: ['POST'])]
    public function update() : Response
    {
        $id = (int)($_POST['id'] ?? 0);
        $this->denyAccessUnlessGranted('MEDICAL_RECORD_EDIT', $id);

        $record = $this->medicalRecordRepository->findById($id);

        if (! $record) {
            return new Response("Медичний запис не знайдено", 404);
        }

        if (! empty($_POST['visit_date'])) {
            try {
                $dt = new \DateTime($_POST['visit_date']);
                $_POST['visit_date'] = $dt->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
            }
        }

        $validator = $this->validator;
        $validator->validate(
            $_POST,
            [
                'diagnosis_code' => ['required'],
                'visit_date' => ['required', 'datetime'],
                'icd_codes' => ['array'],
                'intervention_codes' => ['array'],
            ]
        );

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('medical_records_edit_get', ['id' => $id]);
        }

        $data = $_POST;
        $data['patient_id'] = $record['patient_id'];
        $data['appointment_id'] = $record['appointment_id'];
        $data['doctor_id'] = $record['doctor_id'];

        $this->medicalRecordRepository->update(
            $id,
            $data
        );

        return $this->redirectToRoute('medical_records_show', ['id' => $id]);
    }

    #[Route('/medical-records/attachments/upload', name: 'medical_records_attachments_upload', methods: ['POST'])]
    public function uploadAttachment(#[CurrentUser] User $user) : Response
    {
        $medicalRecordId = (int)($_POST['medical_record_id'] ?? 0);
        $this->denyAccessUnlessGranted('MEDICAL_RECORD_EDIT', $medicalRecordId);

        $record = $this->medicalRecordRepository->findById($medicalRecordId);

        if (! $record) {
            return new Response("Медичний запис не знайдено", 404);
        }

        if (isset($_FILES['attachments']) && ! empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if (UPLOAD_ERR_OK === $_FILES['attachments']['error'][$key]) {
                    $fileData = [
                        'name' => $name,
                        'type' => $_FILES['attachments']['type'][$key],
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$key],
                        'error' => $_FILES['attachments']['error'][$key],
                        'size' => $_FILES['attachments']['size'][$key],
                    ];
                    $this->attachmentService->uploadAttachment(
                        $fileData,
                        'medical_record',
                        $medicalRecordId,
                        $user->getId()
                    );
                }
            }
        }

        return $this->redirectToRoute('medical_records_show', ['id' => $medicalRecordId]);
    }

    #[Route('/medical-records/attachments/download', name: 'medical_records_attachments_download', methods: ['GET'])]
    public function downloadAttachment() : Response
    {
        $attachmentId = (int)($_GET['attachment_id'] ?? 0);
        $attachment = $this->attachmentService->getAttachmentById($attachmentId);

        if (! $attachment || 'medical_record' !== $attachment['entity_type']) {
            return new Response("Вкладення не знайдено", 404);
        }

        $medicalRecordId = (int)$attachment['entity_id'];
        $this->denyAccessUnlessGranted('MEDICAL_RECORD_VIEW', $medicalRecordId);

        $record = $this->medicalRecordRepository->findById($medicalRecordId);

        if (! $record) {
            return new Response("Медичний запис, пов'язаний із вкладенням, не знайдено", 404);
        }

        $uploadBase = dirname(__DIR__, 3) . '/uploads/';
        $candidates = [];
        if (! empty($attachment['filepath'])) {
            $candidates[] = $uploadBase . ltrim($attachment['filepath'], '/');
        }
        $path = $uploadBase . 'medical_record/' . $medicalRecordId . '/';
        $path .= ($attachment['filename'] ?? '');
        $candidates[] = $path;

        $fullPath = null;
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                $fullPath = $path;
                break;
            }
        }

        if (! $fullPath) {
            return new Response("Файл не знайдено на сервері", 404);
        }

        $filename = $attachment['filename'];
        $mimeType = $attachment['mime_type'];
        $size = $attachment['size'];

        return new StreamedResponse(function () use ($fullPath) {
            readfile($fullPath);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => $size,
        ]);
    }
}
