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

namespace App\Http\Appointment\Site;

use App\Domain\Appointment\WaitlistRepository;
use App\Domain\Billing\ServiceRepository;
use App\Domain\Patient\PatientRepository;
use App\Domain\Schedule\SchedulingService;
use App\Domain\User\UserRepository;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicAppointmentController extends AbstractController
{
    private WaitlistRepository $waitlistRepository;
    private PatientRepository $patientRepository;
    private UserRepository $userRepository;
    private SchedulingService $schedulingService;
    private ServiceRepository $serviceRepository;
    private Validator $validator;

    public function __construct(
        WaitlistRepository $waitlistRepository,
        PatientRepository $patientRepository,
        UserRepository $userRepository,
        SchedulingService $schedulingService,
        ServiceRepository $serviceRepository,
        Validator $validator
    ) {
        $this->waitlistRepository = $waitlistRepository;
        $this->patientRepository = $patientRepository;
        $this->userRepository = $userRepository;
        $this->schedulingService = $schedulingService;
        $this->serviceRepository = $serviceRepository;
        $this->validator = $validator;
    }

    #[Route('/book-appointment', name: 'appointment_public_form', methods: ['GET'])]
    public function publicForm() : Response
    {
        $doctors = $this->userRepository->findAllDoctors();
        $services = $this->serviceRepository->findAll();

        $selectedDoctorId = (int)($_GET['doctor_id'] ?? 0);
        $selectedDateStr = $_GET['date'] ?? date('Y-m-d');
        $selectedServiceId = (int)($_GET['service_id'] ?? $services[0]['id'] ?? 0);

        $availableSlots = [];
        if ($selectedDoctorId && $selectedDateStr && $selectedServiceId) {
            try {
                $selectedDate = new \DateTime($selectedDateStr);
                $availableSlots = $this->schedulingService->getAvailableTimeSlots($selectedDoctorId, $selectedDate, $selectedServiceId);
            } catch (\Exception $e) {
                // Invalid date format, handle error or ignore
            }
        }

        $response = $this->render('appointment/public/book.html.twig', [
            'doctors' => $doctors,
            'services' => $services,
            'availableSlots' => $availableSlots,
            'selectedDate' => $selectedDateStr,
            'old' => $_GET,
            'errors' => [],
            'success_message' => $_SESSION['public_success_message'] ?? null,
        ]);
        unset($_SESSION['public_success_message']);
        return $response;
    }

    #[Route('/book-appointment', name: 'appointment_submit_public_form', methods: ['POST'])]
    public function submitPublicForm() : Response
    {
        $rawInput = $_POST;

        $validator = $this->validator;
        $rules = [
            'first_name' => ['required'],
            'last_name' => ['required'],
            'phone_number' => ['required'],
            'email' => ['required', 'email'],
            'doctor_id' => ['required', 'numeric'],
            'service_id' => ['required', 'numeric'],
            'start_time' => ['required', 'datetime'],
            'end_time' => ['required', 'datetime'],
        ];

        $errors = [];
        if (! $validator->validate($rawInput, $rules)) {
            foreach ($validator->getErrors() as $key => $messages) {
                $errors[$key] = $messages;
            }
        } else {
            // Advanced validation: Check if the slot is still available
            $selectedDoctorId = (int)$rawInput['doctor_id'];
            $selectedServiceId = (int)$rawInput['service_id'];
            $startTime = new \DateTime($rawInput['start_time']);

            $availableSlots = $this->schedulingService->getAvailableTimeSlots($selectedDoctorId, $startTime, $selectedServiceId);

            $isSlotAvailable = false;
            foreach ($availableSlots as $slot) {
                if ($slot['time']->format('Y-m-d H:i:s') === $startTime->format('Y-m-d H:i:s') && $slot['available']) {
                    $isSlotAvailable = true;
                    break;
                }
            }

            if (! $isSlotAvailable) {
                $errors['start_time'] = ['The selected time slot is no longer available. Please choose another one.'];
            }
        }

        if (! empty($errors)) {
            $doctors = $this->userRepository->findAllDoctors();
            $services = $this->serviceRepository->findAll();
            $selectedDoctorId = (int)($rawInput['doctor_id'] ?? 0);
            $selectedDateStr = $rawInput['date'] ?? date('Y-m-d');
            $selectedServiceId = (int)($rawInput['service_id'] ?? $services[0]['id'] ?? 0);

            $availableSlots = [];
            if ($selectedDoctorId && $selectedDateStr && $selectedServiceId) {
                try {
                    $selectedDate = new \DateTime($selectedDateStr);
                    $availableSlots = $this->schedulingService->getAvailableTimeSlots($selectedDoctorId, $selectedDate, $selectedServiceId);
                } catch (\Exception $e) {
                }
            }

            return $this->render('appointment/public/book.html.twig', [
                'doctors' => $doctors,
                'services' => $services,
                'availableSlots' => $availableSlots,
                'selectedDate' => $selectedDateStr,
                'old' => $rawInput,
                'errors' => $errors,
                'success_message' => null,
            ]);
        }

        // Find or create patient
        $patient = $this->patientRepository->findByEmail($rawInput['email']);
        if (! $patient) {
            $patientId = $this->patientRepository->save([
                'first_name' => $rawInput['first_name'],
                'last_name' => $rawInput['last_name'],
                'phone' => $rawInput['phone_number'],
                'email' => $rawInput['email'],
                // These are required by DB but not on the form, so use placeholders
                'birth_date' => '1900-01-01',
                'gender' => 'other',
            ]);
            if (! $patientId) {
                $errors['patient'] = ['Could not create a new patient record.'];
                $doctors = $this->userRepository->findAllDoctors();
                $services = $this->serviceRepository->findAll();
                $selectedDoctorId = (int)($rawInput['doctor_id'] ?? 0);
                $selectedDateStr = $rawInput['date'] ?? date('Y-m-d');
                $selectedServiceId = (int)($rawInput['service_id'] ?? $services[0]['id'] ?? 0);
                $availableSlots = [];
                if ($selectedDoctorId && $selectedDateStr && $selectedServiceId) {
                    try {
                        $selectedDate = new \DateTime($selectedDateStr);
                        $availableSlots = $this->schedulingService->getAvailableTimeSlots($selectedDoctorId, $selectedDate, $selectedServiceId);
                    } catch (\Exception $e) {
                    }
                }
                return $this->render('appointment/public/book.html.twig', [
                    'doctors' => $doctors,
                    'services' => $services,
                    'availableSlots' => $availableSlots,
                    'selectedDate' => $selectedDateStr,
                    'old' => $rawInput,
                    'errors' => $errors,
                    'success_message' => null,
                ]);
            }
        } else {
            $patientId = $patient['id'];
        }

        // Add to waitlist
        $waitlistData = [
            'patient_id' => $patientId,
            'desired_doctor_id' => (int)$rawInput['doctor_id'],
            'desired_start_time' => $rawInput['start_time'],
            'contact_phone' => $rawInput['phone_number'],
            'contact_email' => $rawInput['email'],
            'notes' => $rawInput['notes'] ?? null,
        ];

        $result = $this->waitlistRepository->addToWaitlist($waitlistData);

        $_SESSION['public_success_message'] = 'Вашу заявку успішно додано до списку очікування! Ми зв\'яжемося з вами найближчим часом для підтвердження запису.';
        return $this->redirectToRoute('appointment_public_form');
    }
}
