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

namespace App\Http\Department;

use App\Domain\Department\DepartmentRepository;
use App\Domain\Hrm\HrmRepository;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DepartmentController extends AbstractController
{
    private DepartmentRepository $departmentRepository;
    private HrmRepository $hrmRepository;
    private Validator $validator;

    public function __construct(
        DepartmentRepository $departmentRepository,
        HrmRepository $hrmRepository,
        Validator $validator
    ) {
        $this->departmentRepository = $departmentRepository;
        $this->hrmRepository = $hrmRepository;
        $this->validator = $validator;
    }

    #[Route('/departments', name: 'admin_departments_index', methods: ['GET'])]
    public function index() : Response
    {
        $this->denyAccessUnlessGranted('DEPARTMENT_VIEW');

        $departments = $this->departmentRepository->findAll();

        return $this->render('department/index.html.twig', [
            'departments' => $departments,
        ]);
    }

    #[Route('/departments/new', name: 'admin_departments_new', methods: ['GET'])]
    public function create() : Response
    {
        $this->denyAccessUnlessGranted('DEPARTMENT_EDIT');

        $departments = $this->departmentRepository->findAll();
        $parentOptions = array_filter($departments, fn ($dept) => null === $dept['parent_id']);

        return $this->render('department/new.html.twig', [
            'parentOptions' => $parentOptions,
        ]);
    }

    #[Route('/departments/new', name: 'admin_departments_new_post', methods: ['POST'])]
    public function store() : Response
    {
        $this->denyAccessUnlessGranted('DEPARTMENT_EDIT');

        $validator = $this->validator;
        $rules = [
            'name' => ['required'],
            'description' => [],
            'parent_id' => ['integer'],
            'is_active' => [],
            'sort_order' => ['integer'],
        ];

        if (! $validator->validate($_POST, $rules)) {
            $departments = $this->departmentRepository->findAll();
            $parentOptions = array_filter($departments, fn ($dept) => null === $dept['parent_id']);

            $response = $this->render('department/create.html.twig', [
                'old' => $_SESSION['old'] ?? [],
                'errors' => $_SESSION['errors'] ?? [],
                'parentOptions' => $parentOptions,
            ]);
            unset($_SESSION['old'], $_SESSION['errors']);
            return $response;
        }

        if ($this->departmentRepository->save($_POST)) {
            $_SESSION['success_message'] = 'Відділ успішно створено.';
        } else {
            $_SESSION['error_message'] = 'Не вдалося створити відділ.';
        }

        return $this->redirectToRoute('admin_departments_index');
    }

    #[Route('/departments/show', name: 'admin_departments_show', methods: ['GET'])]
    public function show() : Response
    {
        $id = (int)($_GET['id'] ?? 0);
        $this->denyAccessUnlessGranted('DEPARTMENT_VIEW', $id);

        $department = $this->departmentRepository->findById($id);

        if (! $department) {
            return new Response("Відділ не знайдено", 404);
        }

        // Отримуємо співробітників цього відділу
        $employees = $this->hrmRepository->findByDepartment($id);

        return $this->render('department/show.html.twig', [
            'department' => $department,
            'employees' => $employees,
        ]);
    }

    #[Route('/departments/edit', name: 'admin_departments_edit', methods: ['GET'])]
    public function edit() : Response
    {
        $id = (int)($_GET['id'] ?? 0);
        $this->denyAccessUnlessGranted('DEPARTMENT_EDIT', $id);

        $department = $this->departmentRepository->findById($id);

        if (! $department) {
            return new Response("Відділ не знайдено", 404);
        }

        $departments = $this->departmentRepository->findAll();
        $parentOptions = array_filter($departments, fn ($dept) => null === $dept['parent_id']);

        return $this->render('department/edit.html.twig', [
            'department' => $department,
            'parentOptions' => $parentOptions,
        ]);
    }

    #[Route('/departments/edit', name: 'admin_departments_edit_post', methods: ['POST'])]
    public function update() : Response
    {
        $id = (int)($_GET['id'] ?? 0);
        $this->denyAccessUnlessGranted('DEPARTMENT_EDIT', $id);

        $department = $this->departmentRepository->findById($id);

        if (! $department) {
            return new Response("Відділ не знайдено", 404);
        }

        $validator = $this->validator;
        $rules = [
            'name' => ['required'],
            'description' => [],
            'parent_id' => ['integer'],
            'is_active' => [],
            'sort_order' => ['integer'],
        ];

        if (! $validator->validate($_POST, $rules)) {
            $departments = $this->departmentRepository->findAll();
            $parentOptions = array_filter($departments, fn ($dept) => null === $dept['parent_id']);

            return $this->render('department/edit.html.twig', [
                'errors' => $validator->getErrors(),
                'department' => array_merge($department, $_POST),
                'parentOptions' => $parentOptions,
            ]);
        }

        if ($this->departmentRepository->update($id, $_POST)) {
            $_SESSION['success_message'] = 'Дані відділу оновлено.';
        } else {
            $_SESSION['error_message'] = 'Не вдалося оновити дані.';
        }

        return $this->redirectToRoute('admin_departments_show', ['id' => $id]);
    }

    #[Route('/departments/delete', name: 'admin_departments_delete', methods: ['POST'])]
    public function delete() : Response
    {
        $this->denyAccessUnlessGranted('DEPARTMENT_DELETE');

        $id = (int)($_POST['id'] ?? 0);
        $department = $this->departmentRepository->findById($id);

        if ($department) {
            if ($this->departmentRepository->delete($id)) {
                $_SESSION['success_message'] = 'Відділ видалено.';
            } else {
                $_SESSION['error_message'] = 'Не вдалося видалити відділ. Можливо, є дочірні відділи.';
            }
        }

        return $this->redirectToRoute('admin_departments_index');
    }

    #[Route('/departments/toggle-status', name: 'admin_departments_toggle_status', methods: ['POST'])]
    public function toggleStatus() : Response
    {
        $this->denyAccessUnlessGranted('DEPARTMENT_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $department = $this->departmentRepository->findById($id);

        if ($department) {
            $newStatus = $department['is_active'] ? 0 : 1;
            $this->departmentRepository->update($id, ['is_active' => $newStatus]);
            $_SESSION['success_message'] = 'Статус відділу оновлено.';
        }

        return $this->redirectToRoute('admin_departments_index');
    }
}
