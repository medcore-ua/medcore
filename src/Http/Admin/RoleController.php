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

use App\Domain\User\RoleRepository;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RoleController extends AbstractController
{
    private RoleRepository $roleRepository;
    private Validator $validator;

    public function __construct(RoleRepository $roleRepository, Validator $validator)
    {
        $this->roleRepository = $roleRepository;
        $this->validator = $validator;
    }

    #[Route('/roles', name: 'admin_roles', methods: ['GET'])]
    public function listRoles() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $roles = $this->roleRepository->findAll();
        return $this->render('admin/roles/index.html.twig', ['roles' => $roles]);
    }

    #[Route('/roles/edit', name: 'admin_roles_edit', methods: ['GET'])]
    public function editRole() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $role = $this->roleRepository->findById($id);

        if (! $role) {
            return new Response("Роль не знайдено", 404);
        }

        $response = $this->render('admin/roles/edit.html.twig', [
            'role' => $role,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/roles/edit', name: 'admin_roles_edit_post', methods: ['POST'])]
    public function updateRole() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $role = $this->roleRepository->findById($id);

        if (! $role) {
            return new Response("Роль не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'description' => ['required'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_roles_edit', ['id' => $id]);
        }

        // We only allow updating the description to prevent breaking the application's RBAC logic
        $this->roleRepository->update($id, [
            'description' => $_POST['description']
        ]);
        $_SESSION['success_message'] = "Роль успішно оновлено.";
        return $this->redirectToRoute('admin_roles');
    }
}
