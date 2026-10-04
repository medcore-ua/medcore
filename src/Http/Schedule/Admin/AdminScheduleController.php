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

namespace App\Http\Schedule\Admin;

use App\Domain\Schedule\DoctorScheduleRepository;
use App\Domain\Schedule\ScheduleExceptionRepository;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class AdminScheduleController extends AbstractController
{
    private DoctorScheduleRepository $doctorScheduleRepository;
    private ScheduleExceptionRepository $scheduleExceptionRepository;
    private UserRepository $userRepository;

    public function __construct(DoctorScheduleRepository $doctorScheduleRepository, ScheduleExceptionRepository $scheduleExceptionRepository, UserRepository $userRepository)
    {
        $this->doctorScheduleRepository = $doctorScheduleRepository;
        $this->scheduleExceptionRepository = $scheduleExceptionRepository;
        $this->userRepository = $userRepository;
    }

    #[Route('/schedules', name: 'admin_schedules_index', methods: ['GET'])]
    public function adminIndex() : Response
    {
        $this->denyAccessUnlessGranted('SCHEDULE_MANAGE_ANY');
        $allDoctors = $this->userRepository->findAllDoctors();

        return $this->render('schedule/admin.html.twig', [
            'allDoctors' => $allDoctors,
        ]);
    }

    #[Route('/schedules/show', name: 'admin_schedules_show', methods: ['GET'])]
    public function adminShow() : Response
    {
        $this->denyAccessUnlessGranted('SCHEDULE_MANAGE_ANY');

        $doctorId = (int)($_GET['id'] ?? 0);
        $doctor = $this->userRepository->findById($doctorId);

        if (! $doctor) {
            return $this->redirectToRoute('admin_schedules_index');
        }

        $schedules = $this->doctorScheduleRepository->findByDoctor($doctorId);
        $scheduleByDay = [];
        foreach ($schedules as $entry) {
            $scheduleByDay[$entry['day_of_week']] = $entry;
        }

        return $this->render('schedule/show.html.twig', [
            'doctor' => $doctor,
            'scheduleByDay' => $scheduleByDay
        ]);
    }

    #[Route('/schedules/update', name: 'admin_schedules_update', methods: ['POST'])]
    public function adminUpdate(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('SCHEDULE_MANAGE_ANY');
        $targetDoctorId = (int)$_POST['doctor_id'];

        // Authorize if attempting to modify another user's schedule
        if ($targetDoctorId !== $user->getId()) {
            $this->denyAccessUnlessGranted('SCHEDULE_MANAGE_ANY');
        }

        $scheduleData = $_POST['schedule'] ?? [];

        foreach ($scheduleData as $dayOfWeek => $data) {
            $existingSchedule = $this->doctorScheduleRepository->findByDoctorAndDay($targetDoctorId, (int)$dayOfWeek);

            $scheduleEntry = [
                'doctor_id' => $targetDoctorId,
                'day_of_week' => (int)$dayOfWeek,
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'is_available' => isset($data['is_available']) ? 1 : 0,
            ];

            if ($existingSchedule) {
                $this->doctorScheduleRepository->update($existingSchedule['id'], $scheduleEntry);
            } else {
                $this->doctorScheduleRepository->create($scheduleEntry);
            }
        }

        return $this->redirectToRoute('admin_schedules_show', ['id' => $targetDoctorId]);
    }

    #[Route('/schedules/exceptions/add', name: 'admin_schedules_exceptions_add', methods: ['POST'])]
    public function adminAddException(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('SCHEDULE_MANAGE_ANY');
        $targetDoctorId = (int)$_POST['doctor_id'];

        // Authorize if attempting to modify another user's schedule
        if ($targetDoctorId !== $user->getId()) {
            $this->denyAccessUnlessGranted('SCHEDULE_MANAGE_ANY');
        }

        $exceptionData = [
            'doctor_id' => $targetDoctorId,
            'exception_date' => $_POST['exception_date'],
            'start_time' => $_POST['start_time'],
            'end_time' => $_POST['end_time'],
            'is_available' => (int)$_POST['is_available'],
            'notes' => $_POST['notes'] ?? null,
        ];

        $this->scheduleExceptionRepository->create($exceptionData);

        return $this->redirectToRoute('admin_schedules_index');
    }

    #[Route('/schedules/exceptions/delete', name: 'admin_schedules_exceptions_delete', methods: ['POST'])]
    public function adminDeleteException(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('SCHEDULE_MANAGE_ANY');
        $exceptionId = (int)$_POST['exception_id'];
        $exception = $this->scheduleExceptionRepository->findById($exceptionId);

        if (! $exception) {
            return $this->redirectToRoute('admin_schedules_index');
        }

        $targetDoctorId = (int)$exception['doctor_id'];

        // Authorize if attempting to delete another user's exception
        if ($targetDoctorId !== $user->getId()) {
            $this->denyAccessUnlessGranted('SCHEDULE_MANAGE_ANY');
        }

        // Final check that exception belongs to the intended targetDoctorId
        if ($targetDoctorId === $user->getId() || $this->isGranted('SCHEDULE_MANAGE_ANY')) {
            $this->scheduleExceptionRepository->delete($exceptionId);
        }

        return $this->redirectToRoute('admin_schedules_index');
    }

    #[Route('/schedules/edit', name: 'admin_schedules_edit', methods: ['GET'])]
    public function adminEdit() : Response
    {
        $this->denyAccessUnlessGranted('SCHEDULE_MANAGE_ANY');
        $doctorId = (int)($_GET['id'] ?? 0);
        $doctor = $this->userRepository->findById($doctorId);

        if (! $doctor) {
            return $this->redirectToRoute('admin_schedules_index');
        }

        $schedules = $this->doctorScheduleRepository->findByDoctor($doctorId);
        $scheduleByDay = [];
        foreach ($schedules as $entry) {
            $scheduleByDay[$entry['day_of_week']] = $entry;
        }

        return $this->render('schedule/edit.html.twig', [
            'doctor' => $doctor,
            'scheduleByDay' => $scheduleByDay
        ]);
    }
}
