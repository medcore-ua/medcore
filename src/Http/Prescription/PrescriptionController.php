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

namespace App\Http\Prescription;

use App\Domain\Inventory\InventoryItemRepository;
use App\Domain\MedicalRecord\MedicalRecordRepository;
use App\Domain\Patient\PatientRepository;
use App\Domain\Prescription\PrescriptionRepository;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class PrescriptionController extends AbstractController
{
    private PrescriptionRepository $prescriptionRepository;
    private PatientRepository $patientRepository;
    private MedicalRecordRepository $medicalRecordRepository;
    private UserRepository $userRepository;
    private InventoryItemRepository $inventoryItemRepository;
    private Validator $validator;

    public function __construct(
        PrescriptionRepository $prescriptionRepository,
        PatientRepository $patientRepository,
        MedicalRecordRepository $medicalRecordRepository,
        UserRepository $userRepository,
        InventoryItemRepository $inventoryItemRepository,
        Validator $validator
    ) {
        $this->prescriptionRepository = $prescriptionRepository;
        $this->patientRepository = $patientRepository;
        $this->medicalRecordRepository = $medicalRecordRepository;
        $this->userRepository = $userRepository;
        $this->inventoryItemRepository = $inventoryItemRepository;
        $this->validator = $validator;
    }

    #[Route('/prescriptions', name: 'prescription_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('PRESCRIPTION_VIEW');
        $searchTerm = $_GET['search'] ?? '';
        $prescriptions = [];

        if ($this->isGranted('PRESCRIPTION_VIEW_ANY')) {
            $prescriptions = $this->prescriptionRepository->findAll($searchTerm);
        } elseif ($user && $user->getId()) {
            $prescriptions = $this->prescriptionRepository->findByDoctorId($user->getId(), $searchTerm);
        }

        return $this->render('prescription/index.html.twig', [
            'prescriptions' => $prescriptions,
            'searchTerm' => $searchTerm,
        ]);
    }

    #[Route('/prescriptions/new', name: 'prescription_new', methods: ['GET'])]
    public function create(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('PRESCRIPTION_CREATE', ['doctor_id' => $user->getId()]);

        $patientId = (int)($_GET['patient_id'] ?? 0);
        $patient = $this->patientRepository->findById($patientId);

        if (! $patient) {
            return new Response("Пацієнта не знайдено", 404);
        }

        $doctors = $this->userRepository->findAllDoctors();
        $medicalRecords = $this->medicalRecordRepository->findByPatientId($patientId);

        $doctorOptions = [];
        foreach ($doctors as $doctor) {
            $doctorOptions[$doctor['id']] = $doctor['full_name'];
        }

        return $this->render('prescription/new.html.twig', [
            'patient' => $patient,
            'doctors' => $doctorOptions,
            'medicalRecords' => $medicalRecords,
            'currentDoctorId' => $user->getId(),
        ]);
    }

    #[Route('/prescriptions/new', name: 'prescription_store', methods: ['POST'])]
    public function store(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('PRESCRIPTION_CREATE', ['doctor_id' => $_POST['doctor_id'] ?? null]);

        $validator = $this->validator;
        $rules = [
            'patient_id' => ['required'],
            'doctor_id' => ['required'],
            'issue_date' => ['required', 'date'],
            'items' => ['required', 'array'],
            'items.*.medication_name' => ['required'],
            'items.*.dosage' => ['required'],
            'items.*.frequency' => ['required'],
        ];

        if (! $validator->validate($_POST, $rules)) {
            $patient = $this->patientRepository->findById($_POST['patient_id']);
            $doctors = $this->userRepository->findAllDoctors();
            $medicalRecords = $this->medicalRecordRepository->findByPatientId($_POST['patient_id']);

            $doctorOptions = [];
            foreach ($doctors as $doctor) {
                $doctorOptions[$doctor['id']] = $doctor['full_name'];
            }

            return $this->render('prescription/new.html.twig', [
                'errors' => $validator->getErrors(),
                'old' => $_POST,
                'patient' => $patient,
                'doctors' => $doctorOptions,
                'medicalRecords' => $medicalRecords,
                'currentDoctorId' => $user->getId(),
            ]);
        }

        $prescriptionId = $this->prescriptionRepository->save($_POST);

        if ($prescriptionId && ! empty($_POST['items'])) {
            foreach ($_POST['items'] as $itemData) {
                $inventoryItem = $this->inventoryItemRepository->findByName($itemData['medication_name']);

                if ($inventoryItem && isset($itemData['dosage'])) {
                    $quantityToDeduct = (int)$itemData['dosage'];

                    if ($quantityToDeduct > 0) {
                        $this->inventoryItemRepository->decreaseQuantity(
                            $inventoryItem['id'],
                            $quantityToDeduct,
                            $user->getId(),
                            'Виконання рецепту #' . $prescriptionId
                        );
                    }
                }
            }
        }
        return $this->redirectToRoute('patient_show', ['id' => $_POST['patient_id']]);
    }

    #[Route('/prescriptions/show', name: 'prescription_show', methods: ['GET'])]
    public function show() : Response
    {
        $id = (int)($_GET['id'] ?? 0);
        $this->denyAccessUnlessGranted('PRESCRIPTION_VIEW', $id);

        $prescription = $this->prescriptionRepository->findById($id);

        if (! $prescription) {
            return new Response("Рецепт не знайдено", 404);
        }

        return $this->render('prescription/show.html.twig', [
            'prescription' => $prescription,
        ]);
    }
}
