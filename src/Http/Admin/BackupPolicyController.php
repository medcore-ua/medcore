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

use App\Domain\Admin\BackupPolicyRepository;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BackupPolicyController extends AbstractController
{
    private BackupPolicyRepository $backupPolicyRepository;
    private Validator $validator;

    public function __construct(BackupPolicyRepository $backupPolicyRepository, Validator $validator)
    {
        $this->backupPolicyRepository = $backupPolicyRepository;
        $this->validator = $validator;
    }

    #[Route('/backup-policies', name: 'admin_backup_policies', methods: ['GET'])]
    public function listBackupPolicies() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $policies = $this->backupPolicyRepository->findAll();
        return $this->render('admin/backup_policies/index.html.twig', ['policies' => $policies]);
    }

    #[Route('/backup-policies/new', name: 'admin_backup_policies_new', methods: ['GET'])]
    public function createBackupPolicy() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $response = $this->render('admin/backup_policies/new.html.twig', [
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/backup-policies/new', name: 'admin_backup_policies_new_post', methods: ['POST'])]
    public function storeBackupPolicy() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required', 'unique:backup_policies,name'],
            'frequency' => ['required'],
            'retention_days' => ['required', 'numeric', 'min:1'],
            'status' => ['required'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_backup_policies_new');
        }

        $this->backupPolicyRepository->save($_POST);
        $_SESSION['success_message'] = "Політику резервного копіювання успішно створено.";
        return $this->redirectToRoute('admin_backup_policies');
    }

    #[Route('/backup-policies/edit', name: 'admin_backup_policies_edit', methods: ['GET'])]
    public function editBackupPolicy() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $policy = $this->backupPolicyRepository->findById($id);

        if (! $policy) {
            return new Response("Політику резервного копіювання не знайдено", 404);
        }

        $response = $this->render('admin/backup_policies/edit.html.twig', [
            'policy' => $policy,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/backup-policies/edit', name: 'admin_backup_policies_edit_post', methods: ['POST'])]
    public function updateBackupPolicy() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $policy = $this->backupPolicyRepository->findById($id);

        if (! $policy) {
            return new Response("Політику резервного копіювання не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required', 'unique:backup_policies,name,' . $id],
            'frequency' => ['required'],
            'retention_days' => ['required', 'numeric', 'min:1'],
            'status' => ['required'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_backup_policies_edit', ['id' => $id]);
        }

        $this->backupPolicyRepository->update($id, $_POST);
        $_SESSION['success_message'] = "Політику резервного копіювання успішно оновлено.";
        return $this->redirectToRoute('admin_backup_policies');
    }

    #[Route('/backup-policies/delete', name: 'admin_backup_policies_delete', methods: ['POST'])]
    public function deleteBackupPolicy() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $this->backupPolicyRepository->delete($id);
        $_SESSION['success_message'] = "Політику резервного копіювання успішно видалено.";
        return $this->redirectToRoute('admin_backup_policies');
    }
}
