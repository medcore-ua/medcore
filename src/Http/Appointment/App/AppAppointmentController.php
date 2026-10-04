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

namespace App\Http\Appointment\App;

use App\Domain\Appointment\AppointmentRepository;
use App\Domain\Appointment\WaitlistRepository;
use App\Domain\Billing\ServiceRepository;
use App\Domain\Patient\PatientRepository;
use App\Domain\Room\RoomRepository;
use App\Domain\Schedule\SchedulingService;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Shared\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class AppAppointmentController extends AbstractController
{
    private AppointmentRepository $appointmentRepository;
    private WaitlistRepository $waitlistRepository;
    private PatientRepository $patientRepository;
    private UserRepository $userRepository;
    private NotificationService $notificationService;
    private SchedulingService $schedulingService;
    private ServiceRepository $serviceRepository;
    private RoomRepository $roomRepository;
    private \App\Shared\Validation\Validator $validator;

    public function __construct(
        AppointmentRepository $appointmentRepository,
        WaitlistRepository $waitlistRepository,
        PatientRepository $patientRepository,
        UserRepository $userRepository,
        NotificationService $notificationService,
        SchedulingService $schedulingService,
        ServiceRepository $serviceRepository,
        RoomRepository $roomRepository,
        \App\Shared\Validation\Validator $validator
    ) {
        $this->appointmentRepository = $appointmentRepository;
        $this->waitlistRepository = $waitlistRepository;
        $this->patientRepository = $patientRepository;
        $this->userRepository = $userRepository;
        $this->notificationService = $notificationService;
        $this->schedulingService = $schedulingService;
        $this->serviceRepository = $serviceRepository;
        $this->roomRepository = $roomRepository;
        $this->validator = $validator;
    }

    #[Route('/appointments', name: 'appointment_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('APPOINTMENT_VIEW');
        $doctors = $this->userRepository->findAllDoctors();
        $services = $this->serviceRepository->findAll();
        $waitlist = $this->waitlistRepository->getWaitlistEntries();
        $appointments = [];

        if ($this->isGranted('APPOINTMENT_VIEW_ANY')) {
            $appointments = $this->appointmentRepository->findAll();
        } elseif ($this->isGranted('APPOINTMENT_VIEW_OWN')) {
            if ($user->getId()) {
                $appointments = $this->appointmentRepository->findByDoctorId($user->getId());
            }
        }

        $calendarDoctors = [];
        foreach ($doctors as $doctor) {
            if (
                $this->isGranted('APPOINTMENT_VIEW_ANY') ||
                ($this->isGranted('APPOINTMENT_VIEW_OWN') && (int)$doctor['id'] === $user->getId())
            ) {
                $calendarDoctors[] = [
                    'id' => $doctor['id'],
                    'title' => $doctor['full_name']
                ];
            }
        }

        return $this->render('appointment/index.html.twig', [
            'doctors' => $calendarDoctors,
            'waitlist' => $waitlist,
            'appointments' => $appointments,
        ]);
    }

    #[Route('/appointments/waitlist/reject', name: 'appointment_reject_waitlist', methods: ['POST'])]
    public function rejectWaitlist() : Response
    {
        $this->denyAccessUnlessGranted('APPOINTMENT_EDIT');
        $id = (int)($_POST['id'] ?? 0);
        $entry = $this->waitlistRepository->findWaitlistById($id);
        if (! $entry) {
            return new Response("Заявку не знайдено", 404);
        }
        $this->waitlistRepository->updateWaitlistStatus($id, 'cancelled');
        return $this->redirectToRoute('appointment_index');
    }

    #[Route('/appointments/new', name: 'appointment_create', methods: ['GET'])]
    public function create(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('APPOINTMENT_CREATE');

        $patients = $this->patientRepository->findAllActive();
        $doctors = $this->userRepository->findAllDoctors();
        $services = $this->serviceRepository->findAll();
        $rooms = $this->roomRepository->findAvailable();

        $selectedDoctorId = (int)($_GET['doctor_id'] ?? 0);
        $selectedDateStr = $_GET['date'] ?? date('Y-m-d');
        $selectedServiceId = (int)($_GET['service_id'] ?? $services[0]['id'] ?? 0);

        $availableSlots = [];
        if ($selectedDoctorId && $selectedDateStr) {
            try {
                $selectedDate = new \DateTime($selectedDateStr);
                $availableSlots = $this->schedulingService->getAvailableTimeSlots($selectedDoctorId, $selectedDate, $selectedServiceId);
            } catch (\Exception $e) {
                // Invalid date format, handle error or ignore
            }
        }

        // Prefill data from waitlist if applicable
        $prefill = [];
        $waitlistId = (int)($_GET['waitlist_id'] ?? 0);
        if ($waitlistId) {
            $entry = $this->waitlistRepository->findWaitlistById($waitlistId);
            if ($entry) {
                $prefill['waitlist_id'] = $waitlistId;
                if (! empty($entry['desired_doctor_id'])) {
                    $prefill['doctor_id'] = $entry['desired_doctor_id'];
                }
                if (! empty($entry['desired_start_time'])) {
                    try {
                        $dt = $this->normalizeDateTime($entry['desired_start_time']);
                        $prefill['start_time'] = $dt->format('Y-m-d\TH:i');
                    } catch (\Exception $e) {
                        // ignore
                    }
                }
            }
        }

        $patientOptions = [];
        foreach ($patients as $patient) {
            $patientOptions[$patient['id']] = $patient['full_name'];
        }

        $doctorOptions = [];
        if ($this->isGranted('APPOINTMENT_VIEW_OWN') && ! $this->isGranted('APPOINTMENT_VIEW_ANY')) {
            foreach ($doctors as $doctor) {
                if ((int)$doctor['id'] === $user->getId()) {
                    $doctorOptions[$doctor['id']] = $doctor['full_name'];
                    if (empty($prefill['doctor_id'])) {
                        $prefill['doctor_id'] = $doctor['id'];
                    }
                    break;
                }
            }
        } else {
            foreach ($doctors as $doctor) {
                $doctorOptions[$doctor['id']] = $doctor['full_name'];
            }
        }

        $serviceOptions = [];
        foreach ($services as $service) {
            $serviceOptions[$service['id']] = $service['name'] . ' (' . $service['duration_minutes'] . ' хв)';
        }

        $roomOptions = [];
        foreach ($rooms as $room) {
            $roomOptions[$room['id']] = $room['name'] . ' (' . $room['type'] . ')';
        }

        return $this->render('appointment/new.html.twig', [
            'patients' => $patientOptions,
            'doctors' => $doctorOptions,
            'services' => $serviceOptions,
            'servicesForJs' => $services,
            'rooms' => $roomOptions,
            'old' => array_merge($prefill, $_GET),
            'availableSlots' => $availableSlots,
            'selectedDate' => $selectedDateStr,
        ]);
    }

    #[Route('/appointments/new', name: 'appointment_store', methods: ['POST'])]
    public function store(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('APPOINTMENT_CREATE');

        $submittedDoctorId = (int)($_POST['doctor_id'] ?? 0);

        if ($this->isGranted('APPOINTMENT_VIEW_OWN') && ! $this->isGranted('APPOINTMENT_VIEW_ANY') && $user->getId() !== $submittedDoctorId) {
            return new Response("Доступ заборонено: Ви можете створювати записи лише для себе.", 403);
        }

        $rawInput = $_POST;
        $waitlistId = (int)($rawInput['waitlist_id'] ?? 0);
        $errors = null;
        $selectedDoctorId = $submittedDoctorId;
        $startTime = null;

        $validator = $this->validator;
        $rules = [
            'patient_id' => ['required', 'numeric'],
            'doctor_id' => ['required', 'numeric'],
            'service_id' => ['required', 'numeric'],
            'room_id' => ['numeric'],
            'start_time' => ['required', 'datetime'],
            'end_time' => ['required', 'datetime'],
        ];

        if (! $validator->validate($rawInput, $rules)) {
            $errors = [];
            foreach ($validator->getErrors() as $key => $messages) {
                $errors[$key] = is_array($messages) ? reset($messages) : $messages;
            }
        } else {
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
                $errors['start_time'] = 'The selected time slot is no longer available. Please choose another one.';
            }

            $roomValidation = $this->schedulingService->validateAppointmentBooking([
                'doctor_id' => $selectedDoctorId,
                'start_time' => $rawInput['start_time'],
                'end_time' => $rawInput['end_time'],
                'room_id' => $rawInput['room_id'] ?? null
            ]);

            if (! $roomValidation['valid']) {
                foreach ($roomValidation['errors'] as $error) {
                    $errors[$error['field']] = $error['message'];
                }
            }
        }

        if (! empty($errors)) {
            $patients = $this->patientRepository->findAllActive();
            $doctors = $this->userRepository->findAllDoctors();
            $services = $this->serviceRepository->findAll();
            $rooms = $this->roomRepository->findAvailable();

            $patientOptions = [];
            foreach ($patients as $patient) {
                $patientOptions[$patient['id']] = $patient['full_name'];
            }
            $doctorOptions = [];
            foreach ($doctors as $doctor) {
                $doctorOptions[$doctor['id']] = $doctor['full_name'];
            }

            $selectedDoctorId = (int)($rawInput['doctor_id'] ?? 0);
            $selectedServiceId = (int)($rawInput['service_id'] ?? 0);

            $availableSlots = [];
            $selectedDateStr = date('Y-m-d');
            if (! empty($rawInput['start_time'])) {
                try {
                    $startTime = new \DateTime($rawInput['start_time']);
                    $selectedDateStr = $startTime->format('Y-m-d');
                    if ($selectedDoctorId && $selectedServiceId) {
                        $availableSlots = $this->schedulingService->getAvailableTimeSlots($selectedDoctorId, $startTime, $selectedServiceId);
                    }
                } catch (\Exception $e) {
                }
            }

            $roomOptions = [];
            foreach ($rooms as $room) {
                $roomOptions[$room['id']] = $room['name'] . ' (' . $room['type'] . ')';
            }

            $serviceOptions = [];
            foreach ($services as $service) {
                $serviceOptions[$service['id']] = $service['name'] . ' (' . $service['duration_minutes'] . ' хв)';
            }

            return $this->render('appointment/new.html.twig', [
                'errors' => $errors,
                'old' => $rawInput,
                'patients' => $patientOptions,
                'doctors' => $doctorOptions,
                'services' => $serviceOptions,
                'servicesForJs' => $services,
                'rooms' => $roomOptions,
                'availableSlots' => $availableSlots,
                'selectedDate' => $selectedDateStr,
            ]);
        }

        $dataToSave = $rawInput;
        $dataToSave['waitlist_id'] = ($waitlistId > 0) ? $waitlistId : null;

        $this->appointmentRepository->save($dataToSave);

        if ($waitlistId > 0) {
            $this->waitlistRepository->updateWaitlistStatus($waitlistId, 'booked');
        }

        $patient = $this->patientRepository->findById((int)$rawInput['patient_id']);
        $doctor = $this->userRepository->findById($selectedDoctorId);
        if ($patient && $doctor && $startTime instanceof \DateTime) {
            $message = sprintf(
                'New appointment: Patient %s with Dr. %s at %s.',
                $patient['first_name'] . ' ' . $patient['last_name'],
                $doctor['first_name'] . ' ' . $doctor['last_name'],
                $startTime->format('Y-m-d H:i')
            );
            $this->notificationService->createNotification($doctor['id'], $message);
        }

        return $this->redirectToRoute('appointment_index');
    }

    private function normalizeDateTime(string $value) : \DateTime
    {
        $formats = [
            'Y-m-d\TH:i',
            'Y-m-d H:i',
            'Y-m-d H:i:s',
            'd.m.Y, H:i',
            'd.m.Y H:i',
            'd.m.Y',
        ];
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt instanceof \DateTime) {
                return $dt;
            }
        }
        return new \DateTime($value);
    }

    #[Route('/api/appointments', name: 'appointment_json', methods: ['GET'])]
    public function getAppointmentsJson(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('APPOINTMENT_VIEW');
        $start = $_GET['start'] ?? null;
        $end = $_GET['end'] ?? null;
        $appointments = [];

        if ($this->isGranted('APPOINTMENT_VIEW_ANY')) {
            if ($start && $end) {
                $appointments = $this->appointmentRepository->findByDateRange($start, $end);
            } else {
                $appointments = $this->appointmentRepository->findAll();
            }
        } elseif ($this->isGranted('APPOINTMENT_VIEW_OWN')) {
            if ($user->getId()) {
                if ($start && $end) {
                    $appointments = $this->appointmentRepository->findByDoctorIdAndDateRange($user->getId(), $start, $end);
                } else {
                    $appointments = $this->appointmentRepository->findByDoctorId($user->getId());
                }
            }
        }

        $events = [];

        $statusColors = [
            'scheduled' => '#2185d0',
            'completed' => '#21ba45',
            'cancelled' => '#db2828',
            'no-show' => '#fbbd08',
        ];

        foreach ($appointments as $appointment) {
            $events[] = [
                'title' => $appointment['patient_name'] . ' (' . $appointment['doctor_name'] . ')',
                'start' => $appointment['start_time'],
                'end' => $appointment['end_time'],
                'id' => $appointment['id'],
                'color' => $statusColors[$appointment['status']] ?? '#767676',
                'resourceId' => $appointment['doctor_id'],
            ];

            if (! empty($appointment['room_id'])) {
                $events[] = [
                    'title' => $appointment['patient_name'] . ' (' . ($appointment['room_name'] ?? 'Кімната ' . $appointment['room_id']) . ')',
                    'start' => $appointment['start_time'],
                    'end' => $appointment['end_time'],
                    'id' => 'room_' . $appointment['id'],
                    'color' => $statusColors[$appointment['status']] ?? '#767676',
                    'resourceId' => 'room_' . $appointment['room_id'],
                ];
            }
        }

        return new JsonResponse($events);
    }

    #[Route('/appointments/show', name: 'appointment_show', methods: ['GET'])]
    public function show() : Response
    {
        $id = (int)($_GET['id'] ?? 0);
        $this->denyAccessUnlessGranted('APPOINTMENT_VIEW', $id);

        $appointment = $this->appointmentRepository->findById($id);

        if (! $appointment) {
            return new Response("Запис не знайдено", 404);
        }

        return $this->render('appointment/show.html.twig', ['appointment' => $appointment]);
    }

    #[Route('/appointments/edit', name: 'appointment_edit', methods: ['GET'])]
    public function edit(#[CurrentUser] User $user) : Response
    {
        $id = (int)($_GET['id'] ?? 0);
        $this->denyAccessUnlessGranted('APPOINTMENT_EDIT', $id);

        $appointment = $this->appointmentRepository->findById($id);

        if (! $appointment) {
            return new Response("Запис не знайдено", 404);
        }

        $patients = $this->patientRepository->findAllActive();
        $doctors = $this->userRepository->findAllDoctors();
        $rooms = $this->roomRepository->findAll();

        $patientOptions = [];
        foreach ($patients as $patient) {
            $patientOptions[$patient['id']] = $patient['full_name'];
        }

        $doctorOptions = [];
        if ($this->isGranted('APPOINTMENT_VIEW_OWN') && ! $this->isGranted('APPOINTMENT_VIEW_ANY')) {
            foreach ($doctors as $doctor) {
                if ((int)$doctor['id'] === $user->getId()) {
                    $doctorOptions[$doctor['id']] = $doctor['full_name'];
                    break;
                }
            }
        } else {
            foreach ($doctors as $doctor) {
                $doctorOptions[$doctor['id']] = $doctor['full_name'];
            }
        }

        $roomOptions = [];
        foreach ($rooms as $room) {
            $roomOptions[$room['id']] = $room['name'] . ' (' . $room['type'] . ')';
        }

        return $this->render('appointment/edit.html.twig', [
            'appointment' => $appointment,
            'patients' => $patientOptions,
            'doctors' => $doctorOptions,
            'rooms' => $roomOptions,
        ]);
    }

    #[Route('/appointments/edit', name: 'appointment_update', methods: ['POST'])]
    public function update() : Response
    {
        $id = (int)($_POST['id'] ?? 0);
        $this->denyAccessUnlessGranted('APPOINTMENT_EDIT', $id);

        $rawInput = $_POST;

        foreach (['start_time', 'end_time'] as $field) {
            if (! empty($_POST[$field])) {
                try {
                    $dt = $this->normalizeDateTime($_POST[$field]);
                    $_POST[$field] = $dt->format('Y-m-d H:i:s');
                    $_POST[$field . '_input'] = $dt->format('Y-m-d\TH:i');
                } catch (\Exception $e) {
                    // keep raw
                }
            }
        }

        $id = (int)($_POST['id'] ?? 0);
        $appointment = $this->appointmentRepository->findById($id);

        if (! $appointment) {
            return new Response("Запис не знайдено", 404);
        }

        $errors = null;
        $validator = $this->validator;
        $rules = [
            'patient_id' => ['required', 'numeric'],
            'doctor_id' => ['required', 'numeric'],
            'room_id' => ['numeric'],
            'start_time' => ['required', 'datetime'],
            'end_time' => ['required', 'datetime'],
            'status' => ['required', 'in:scheduled,completed,cancelled,no-show'],
        ];

        if (! empty($_POST['start_time']) && ! empty($_POST['end_time'])) {
            if (strtotime($_POST['end_time']) <= strtotime($_POST['start_time'])) {
                $validator->addError('end_time', 'Час закінчення має бути пізніше за час початку.');
            }
        }

        if (! $validator->validate($_POST, $rules)) {
            $errors = [];
            foreach ($validator->getErrors() as $key => $messages) {
                $errors[$key] = is_array($messages) ? reset($messages) : $messages;
            }
        }

        if (! empty($_POST['start_time']) && ! empty($_POST['end_time'])) {
            $roomValidation = $this->schedulingService->validateAppointmentBooking([
                'doctor_id' => (int)$_POST['doctor_id'],
                'start_time' => $_POST['start_time'],
                'end_time' => $_POST['end_time'],
                'room_id' => $_POST['room_id'] ?? null,
                'exclude_id' => $id
            ]);

            if (! $roomValidation['valid']) {
                foreach ($roomValidation['errors'] as $error) {
                    $errors[$error['field']] = $error['message'];
                }
            }
        }

        if (! empty($errors)) {
            $patients = $this->patientRepository->findAllActive();
            $doctors = $this->userRepository->findAllDoctors();
            $rooms = $this->roomRepository->findAll();
            $patientOptions = [];
            foreach ($patients as $patient) {
                $patientOptions[$patient['id']] = $patient['full_name'];
            }
            $doctorOptions = [];
            foreach ($doctors as $doctor) {
                $doctorOptions[$doctor['id']] = $doctor['full_name'];
            }
            $roomOptions = [];
            foreach ($rooms as $room) {
                $roomOptions[$room['id']] = $room['name'] . ' (' . $room['type'] . ')';
            }

            $patients = $this->patientRepository->findAllActive();
            $doctors = $this->userRepository->findAllDoctors();
            $patientOptions = [];
            foreach ($patients as $patient) {
                $patientOptions[$patient['id']] = $patient['full_name'];
            }
            $doctorOptions = [];
            foreach ($doctors as $doctor) {
                $doctorOptions[$doctor['id']] = $doctor['full_name'];
            }

            $roomOptions = [];
            foreach ($rooms as $room) {
                $roomOptions[$room['id']] = $room['name'] . ' (' . $room['type'] . ')';
            }

            return $this->render('appointment/edit.html.twig', [
                'errors' => $errors,
                'appointment' => $appointment,
                'old' => array_merge($rawInput, [
                    'start_time' => $_POST['start_time_input'] ?? $rawInput['start_time'] ?? null,
                    'end_time' => $_POST['end_time_input'] ?? $rawInput['end_time'] ?? null,
                ]),
                'patients' => $patientOptions,
                'doctors' => $doctorOptions,
                'rooms' => $roomOptions,
            ]);
        }

        $this->appointmentRepository->update($id, $_POST);
        return $this->redirectToRoute('appointment_show', ['id' => $id]);
    }

    #[Route('/appointments/cancel', name: 'appointment_cancel', methods: ['POST'])]
    public function cancel() : Response
    {
        $id = (int)($_POST['id'] ?? 0);
        $this->denyAccessUnlessGranted('APPOINTMENT_CANCEL', $id);

        $appointment = $this->appointmentRepository->findById($id);

        if (! $appointment) {
            return new Response("Запис не знайдено", 404);
        }

        $this->appointmentRepository->updateStatus($id, 'cancelled');

        $patient = $this->patientRepository->findById($appointment['patient_id']);
        $doctor = $this->userRepository->findById($appointment['doctor_id']);

        if ($patient) {
            $messagePatient = sprintf(
                'Ваш запис до лікаря %s на %s скасовано.',
                $doctor['first_name'] . ' ' . $doctor['last_name'],
                $appointment['start_time']
            );
        }
        if ($doctor) {
            $messageDoctor = sprintf(
                'Запис пацієнта %s на %s скасовано.',
                $patient['first_name'] . ' ' . $patient['last_name'],
                $appointment['start_time']
            );
            $this->notificationService->createNotification($doctor['id'], $messageDoctor);
        }

        return $this->redirectToRoute('appointment_show', ['id' => $id]);
    }

    #[Route('/appointments/waitlist', name: 'appointment_waitlist', methods: ['GET'])]
    public function showWaitlist() : Response
    {
        $this->denyAccessUnlessGranted('APPOINTMENT_VIEW_ANY');

        $waitlistEntries = $this->waitlistRepository->getWaitlistEntries('pending');
        $patients = $this->patientRepository->findAllActive();
        $doctors = $this->userRepository->findAllDoctors();

        $patientOptions = [];
        foreach ($patients as $patient) {
            $patientOptions[$patient['id']] = $patient['full_name'];
        }

        $doctorOptions = [];
        foreach ($doctors as $doctor) {
            $doctorOptions[$doctor['id']] = $doctor['full_name'];
        }

        return $this->render('appointment/waitlist.html.twig', [
            'waitlistEntries' => $waitlistEntries,
            'patients' => $patientOptions,
            'doctors' => $doctorOptions,
        ]);
    }

    #[Route('/appointments/waitlist/add', name: 'appointment_add_waitlist', methods: ['POST'])]
    public function addPatientToWaitlist() : Response
    {
        $this->denyAccessUnlessGranted('APPOINTMENT_CREATE');

        $validator = $this->validator;
        $rules = [
            'patient_id' => ['required'],
        ];

        if (! $validator->validate($_POST, $rules)) {
            $waitlistEntries = $this->waitlistRepository->getWaitlistEntries('pending');
            $patients = $this->patientRepository->findAllActive();
            $doctors = $this->userRepository->findAllDoctors();

            $patientOptions = [];
            foreach ($patients as $patient) {
                $patientOptions[$patient['id']] = $patient['full_name'];
            }

            $doctorOptions = [];
            foreach ($doctors as $doctor) {
                $doctorOptions[$doctor['id']] = $doctor['full_name'];
            }

            return $this->render('appointment/waitlist.html.twig', [
                'errors' => $validator->getErrors(),
                'old' => $_POST,
                'waitlistEntries' => $waitlistEntries,
                'patients' => $patientOptions,
                'doctors' => $doctorOptions,
            ]);
        }

        $this->waitlistRepository->addToWaitlist($_POST);
        return $this->redirectToRoute('appointment_waitlist');
    }

    #[Route('/appointments/load-analytics', name: 'appointment_load_analytics', methods: ['GET'])]
    public function showLoadAnalytics() : Response
    {
        $this->denyAccessUnlessGranted('APPOINTMENT_VIEW_ANY');

        $date = $_GET['date'] ?? date('Y-m-d');
        $doctorLoad = $this->appointmentRepository->getDoctorDailyLoad($date);

        return $this->render('appointment/load_analytics.html.twig', [
            'date' => $date,
            'doctorLoad' => $doctorLoad,
        ]);
    }

    #[Route('/api/appointments/available-slots', name: 'appointment_available_slots_api', methods: ['GET'])]
    public function getAvailableSlotsApi() : Response
    {
        $selectedDoctorId = (int)($_GET['doctor_id'] ?? 0);
        $selectedDateStr = $_GET['date'] ?? null;
        $selectedServiceId = (int)($_GET['service_id'] ?? 0);

        if (! $selectedDoctorId || ! $selectedDateStr || ! $selectedServiceId) {
            return new JsonResponse(['error' => 'Doctor, service, and date are required.']);
        }

        try {
            $selectedDate = new \DateTime($selectedDateStr);
            $availableSlots = $this->schedulingService->getAvailableTimeSlots($selectedDoctorId, $selectedDate, $selectedServiceId);

            $formattedSlots = [];
            foreach ($availableSlots as $slot) {
                $formattedSlots[] = [
                    'value' => $slot['time']->format('Y-m-d H:i:s'),
                    'label' => $slot['time']->format('H:i'),
                    'available' => $slot['available'],
                    'is_in_past' => $slot['is_in_past'],
                    'is_booked' => $slot['is_booked']
                ];
            }

            return new JsonResponse($formattedSlots);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Invalid date format.'], 400);
        }
    }

    #[Route('/appointments/waitlist/fulfill', name: 'appointment_fulfill_waitlist', methods: ['GET'])]
    public function fulfillWaitlist() : Response
    {
        $this->denyAccessUnlessGranted('APPOINTMENT_CREATE');
        $id = (int)($_GET['id'] ?? 0);
        $entry = $this->waitlistRepository->findWaitlistById($id);
        if (! $entry) {
            return new Response("Заявку не знайдено", 404);
        }

        $patients = $this->patientRepository->findAllActive();
        $doctors = $this->userRepository->findAllDoctors();
        $services = $this->serviceRepository->findAll();
        $rooms = $this->roomRepository->findAll();

        $patientOptions = [];
        foreach ($patients as $patient) {
            $patientOptions[$patient['id']] = $patient['full_name'];
        }

        $doctorOptions = [];
        foreach ($doctors as $doctor) {
            $doctorOptions[$doctor['id']] = $doctor['full_name'];
        }

        $serviceOptions = [];
        foreach ($services as $service) {
            $serviceOptions[$service['id']] = $service['name'] . ' (' . $service['duration_minutes'] . ' хв)';
        }

        $roomOptions = [];
        foreach ($rooms as $room) {
            $roomOptions[$room['id']] = $room['name'] . ' (' . $room['type'] . ')';
        }

        $prefill = [
            'patient_id' => $entry['patient_id'],
            'doctor_id' => $entry['desired_doctor_id'],
            'waitlist_id' => $id,
        ];

        return $this->render('appointment/new.html.twig', [
            'patients' => $patientOptions,
            'doctors' => $doctorOptions,
            'services' => $serviceOptions,
            'servicesForJs' => $services,
            'rooms' => $roomOptions,
            'old' => array_merge($prefill, $_GET),
            'availableSlots' => [],
            'selectedDate' => date('Y-m-d'),
        ]);
    }

    #[Route('/appointments/waitlist/cancel', name: 'appointment_cancel_waitlist', methods: ['POST'])]
    public function cancelWaitlist() : Response
    {
        $this->denyAccessUnlessGranted('APPOINTMENT_EDIT');
        $id = (int)($_POST['id'] ?? 0);
        $entry = $this->waitlistRepository->findWaitlistById($id);
        if (! $entry) {
            return new Response("Заявку не знайдено", 404);
        }
        $this->waitlistRepository->updateWaitlistStatus($id, 'cancelled');
        return $this->redirectToRoute('appointment_waitlist');
    }
}
