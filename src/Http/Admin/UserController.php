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

namespace App\Http\Admin;

use App\Domain\User\MfaService;
use App\Domain\User\RoleRepository;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class UserController extends AbstractController
{
    private UserRepository $userRepository;
    private RoleRepository $roleRepository;
    private MfaService $mfaService;
    private Validator $validator;

    public function __construct(
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        MfaService $mfaService,
        Validator $validator
    ) {
        $this->userRepository = $userRepository;
        $this->roleRepository = $roleRepository;
        $this->mfaService = $mfaService;
        $this->validator = $validator;
    }

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function users() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $searchTerm = $_GET['search'] ?? '';
        $users = $this->userRepository->findAll($searchTerm);
        $roles = $this->roleRepository->findAll();
        $roleMap = [];
        foreach ($roles as $role) {
            $roleMap[$role['id']] = $role['name'];
        }

        foreach ($users as &$user) {
            $user['role_name'] = $roleMap[$user['role_id']] ?? 'Невідома';
        }
        unset($user); // Break the reference with the last element

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
            'searchTerm' => $searchTerm,
        ]);
    }

    #[Route('/users/new', name: 'admin_users_new', methods: ['GET'])]
    public function createUser() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $roleOptions = $this->buildRoleOptionsByPriority();

        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        $errors = $_SESSION['errors'] ?? [];
        unset($_SESSION['errors']);

        return $this->render('admin/users/new.html.twig', [
            'roles' => $roleOptions,
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    #[Route('/users/new', name: 'admin_users_new_post', methods: ['POST'])]
    public function storeUser() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'first_name' => ['required'],
            'last_name' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:6'],
            'role_id' => ['required', 'numeric'],
        ]);

        if ($this->userRepository->findByEmail($_POST['email'])) {
            $validator->addError('email', 'Цей email вже використовується.');
        }

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_users_new');
        }

        $this->userRepository->save($_POST);
        $_SESSION['success_message'] = "Користувача успішно створено.";
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/show', name: 'admin_users_show', methods: ['GET'])]
    public function showUser() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $user = $this->userRepository->findById($id);

        if (! $user) {
            return new Response("Користувача не знайдено", 404);
        }

        $role = $this->roleRepository->findById($user['role_id']);
        $user['role_name'] = $role['name'] ?? 'Невідома';

        return $this->render('admin/users/show.html.twig', ['user' => $user]);
    }

    #[Route('/users/edit', name: 'admin_users_edit', methods: ['GET'])]
    public function editUser() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $user = $this->userRepository->findById($id);

        if (! $user) {
            return new Response("Користувача не знайдено", 404);
        }

        $roleOptions = $this->buildRoleOptionsByPriority();

        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        $errors = $_SESSION['errors'] ?? [];
        unset($_SESSION['errors']);

        return $this->render('admin/users/edit.html.twig', [
            'user' => $user,
            'roles' => $roleOptions,
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    private function buildRoleOptionsByPriority() : array
    {
        $roles = $this->roleRepository->findAll();
        $priority = [
            'admin' => 1,
            'medical_manager' => 2,
            'doctor' => 3,
            'registrar' => 4,
            'nurse' => 5,
            'lab_technician' => 6,
            'billing' => 7,
            'inventory_manager' => 8,
        ];

        usort($roles, function ($a, $b) use ($priority) {
            $pa = $priority[$a['name']] ?? 999;
            $pb = $priority[$b['name']] ?? 999;
            if ($pa === $pb) {
                return strcasecmp($a['name'], $b['name']);
            }
            return $pa <=> $pb;
        });

        $roleOptions = [];
        foreach ($roles as $role) {
            $roleOptions[$role['id']] = $role['name'];
        }

        return $roleOptions;
    }

    #[Route('/users/edit', name: 'admin_users_edit_post', methods: ['POST'])]
    public function updateUser() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $user = $this->userRepository->findById($id);

        if (! $user) {
            return new Response("Користувача не знайдено", 404);
        }

        // TODO: Add validation
        $validator = $this->validator;
        $rules = [
            'first_name' => ['required'],
            'last_name' => ['required'],
            'email' => ['required', 'email'],
            'role_id' => ['required', 'numeric'],
        ];

        if (! empty($_POST['password'])) {
            $rules['password'] = ['min:6'];
        }

        $validator->validate($_POST, $rules);

        if ($this->userRepository->findByEmailExcludingId($_POST['email'], $id)) {
            $validator->addError('email', 'Цей email вже використовується іншим користувачем.');
        }

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_users_edit', ['id' => $id]);
        }

        $this->userRepository->update($id, $_POST);
        $_SESSION['success_message'] = "Дані користувача успішно оновлено.";
        return $this->redirectToRoute('admin_users_show', ['id' => $id]);
    }

    #[Route('/users/delete', name: 'admin_users_delete', methods: ['POST'])]
    public function deleteUser(#[CurrentUser] User $currentUser) : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $user = $this->userRepository->findById($id);

        if (! $user) {
            return new Response("Користувача не знайдено", 404);
        }

        // Prevent admin from deleting themselves
        if ($user['id'] === $currentUser->getId()) {
            $_SESSION['error_message'] = "Ви не можете видалити свій власний обліковий запис.";
            return $this->redirectToRoute('admin_users');
        }

        $this->userRepository->delete($id);
        $_SESSION['success_message'] = "Користувача успішно видалено.";
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/disable-mfa', name: 'admin_users_disable_mfa', methods: ['POST'])]
    public function disableUserMfa() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $user = $this->userRepository->findById($id);

        if (! $user) {
            return new Response("Користувача не знайдено", 404);
        }

        $this->mfaService->disableMfaForUser($id);

        $_SESSION['success_message'] = "2FA для користувача " . $user['email'] . " успішно вимкнено.";
        return $this->redirectToRoute('admin_users');
    }
}
