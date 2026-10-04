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

namespace App\Http\Hrm;

use App\Domain\Department\DepartmentRepository;
use App\Domain\Hrm\HrmRepository;
use App\Domain\User\UserRepository;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HrmController extends AbstractController
{
    private HrmRepository $hrmRepository;
    private UserRepository $userRepository;
    private DepartmentRepository $departmentRepository;
    private Validator $validator;

    public function __construct(
        HrmRepository $hrmRepository,
        UserRepository $userRepository,
        DepartmentRepository $departmentRepository,
        Validator $validator
    ) {
        $this->hrmRepository = $hrmRepository;
        $this->userRepository = $userRepository;
        $this->departmentRepository = $departmentRepository;
        $this->validator = $validator;
    }

    #[Route('/hrm', name: 'hrm_index', methods: ['GET'])]
    public function index() : Response
    {
        $this->denyAccessUnlessGranted('HRM_VIEW');

        $employees = $this->hrmRepository->findAll();

        return $this->render('hrm/index.html.twig', [
            'employees' => $employees,
        ]);
    }

    #[Route('/hrm/new', name: 'hrm_new', methods: ['GET'])]
    public function create() : Response
    {
        $this->denyAccessUnlessGranted('HRM_EDIT');

        $users = $this->userRepository->findAll();
        $departments = $this->departmentRepository->findAllActive();

        return $this->render('hrm/new.html.twig', [
            'users' => $users,
            'departments' => $departments,
        ]);
    }

    #[Route('/hrm/new', name: 'hrm_store', methods: ['POST'])]
    public function store() : Response
    {
        $this->denyAccessUnlessGranted('HRM_EDIT');

        $validator = $this->validator;
        $rules = [
            'first_name' => ['required'],
            'last_name' => ['required'],
            'position' => ['required'],
            'hire_date' => ['required'],
        ];

        if (! $validator->validate($_POST, $rules)) {
            // Re-fetch users for the form
            $users = $this->userRepository->findAll();
            return $this->render('hrm/new.html.twig', [
                'errors' => $validator->getErrors(),
                'old' => $_POST,
                'users' => $users,
            ]);
        }

        if ($this->hrmRepository->save($_POST)) {
            $_SESSION['success_message'] = 'Співробітника успішно додано.';
        } else {
            $_SESSION['error_message'] = 'Не вдалося додати співробітника.';
        }

        return $this->redirectToRoute('hrm_index');
    }

    #[Route('/hrm/show', name: 'hrm_show', methods: ['GET'])]
    public function show() : Response
    {
        $this->denyAccessUnlessGranted('HRM_VIEW');
        $id = (int)($_GET['id'] ?? 0);

        $employee = $this->hrmRepository->findById($id);

        if (! $employee) {
            return new Response("Співробітника не знайдено", 404);
        }

        return $this->render('hrm/show.html.twig', [
            'employee' => $employee,
        ]);
    }

    #[Route('/hrm/edit', name: 'hrm_edit', methods: ['GET'])]
    public function edit() : Response
    {
        $this->denyAccessUnlessGranted('HRM_EDIT');
        $id = (int)($_GET['id'] ?? 0);

        $employee = $this->hrmRepository->findById($id);

        if (! $employee) {
            return new Response("Співробітника не знайдено", 404);
        }

        $users = $this->userRepository->findAll();
        $departments = $this->departmentRepository->findAllActive();

        return $this->render('hrm/edit.html.twig', [
            'employee' => $employee,
            'users' => $users,
            'departments' => $departments,
        ]);
    }

    #[Route('/hrm/edit', name: 'hrm_update', methods: ['POST'])]
    public function update() : Response
    {
        $this->denyAccessUnlessGranted('HRM_EDIT');
        $id = (int)($_GET['id'] ?? 0);

        $employee = $this->hrmRepository->findById($id);

        if (! $employee) {
            return new Response("Співробітника не знайдено", 404);
        }

        $validator = $this->validator;
        $rules = [
            'first_name' => ['required'],
            'last_name' => ['required'],
            'position' => ['required'],
            'hire_date' => ['required'],
        ];

        if (! $validator->validate($_POST, $rules)) {
            // Re-fetch users for the form
            $users = $this->userRepository->findAll();
            return $this->render('hrm/edit.html.twig', [
                'errors' => $validator->getErrors(),
                'employee' => array_merge($employee, $_POST),
                'users' => $users,
            ]);
        }

        if ($this->hrmRepository->update($id, $_POST)) {
            $_SESSION['success_message'] = 'Дані співробітника оновлено.';
        } else {
            $_SESSION['error_message'] = 'Не вдалося оновити дані.';
        }

        return $this->redirectToRoute('hrm_show', ['id' => $id]);
    }

    #[Route('/hrm/toggle-status', name: 'hrm_toggle_status', methods: ['POST'])]
    public function toggleStatus() : Response
    {
        $this->denyAccessUnlessGranted('HRM_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $employee = $this->hrmRepository->findById($id);

        if ($employee) {
            $newStatus = 'active' === $employee['status'] ? 'terminated' : 'active';
            $this->hrmRepository->updateStatus($id, $newStatus);
            $_SESSION['success_message'] = 'Статус співробітника оновлено.';
        }

        return $this->redirectToRoute('hrm_show', ['id' => $id]);
    }
}
