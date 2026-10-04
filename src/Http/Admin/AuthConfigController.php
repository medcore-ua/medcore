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

use App\Domain\Admin\AuthConfigRepository;
use App\Http\User\OAuthController;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthConfigController extends AbstractController
{
    private AuthConfigRepository $authConfigRepository;
    private Validator $validator;

    public function __construct(AuthConfigRepository $authConfigRepository, Validator $validator)
    {
        $this->authConfigRepository = $authConfigRepository;
        $this->validator = $validator;
    }

    #[Route('/auth-configs', name: 'admin_auth_configs', methods: ['GET'])]
    public function listAuthConfigs() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $configs = $this->authConfigRepository->findAll();
        return $this->render('admin/auth_configs/index.html.twig', ['configs' => $configs]);
    }

    #[Route('/auth-configs/new', name: 'admin_auth_configs_new', methods: ['GET'])]
    public function createAuthConfig() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $supportedProviders = OAuthController::getSupportedProviders();

        $response = $this->render('admin/auth_configs/new.html.twig', [
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
            'supportedProviders' => $supportedProviders,
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/auth-configs/new', name: 'admin_auth_configs_new_post', methods: ['POST'])]
    public function storeAuthConfig() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'provider' => ['required', 'unique:auth_configs,provider'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_auth_configs_new');
        }

        $data = $_POST;
        $data['is_active'] = isset($_POST['is_active']) ? 1 : 0;

        $this->authConfigRepository->save($data);
        $_SESSION['success_message'] = "Конфігурацію аутентифікації успішно створено.";
        return $this->redirectToRoute('admin_auth_configs');
    }

    #[Route('/auth-configs/edit', name: 'admin_auth_configs_edit', methods: ['GET'])]
    public function editAuthConfig() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $config = $this->authConfigRepository->findById($id);

        if (! $config) {
            return new Response("Конфігурацію аутентифікації не знайдено", 404);
        }
        $supportedProviders = OAuthController::getSupportedProviders();

        $response = $this->render('admin/auth_configs/edit.html.twig', [
            'config' => $config,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
            'supportedProviders' => $supportedProviders,
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/auth-configs/edit', name: 'admin_auth_configs_edit_post', methods: ['POST'])]
    public function updateAuthConfig() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $config = $this->authConfigRepository->findById($id);

        if (! $config) {
            return new Response("Конфігурацію аутентифікації не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'provider' => ['required', 'unique:auth_configs,provider,' . $id],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_auth_configs_edit', ['id' => $id]);
        }

        $data = $_POST;
        $data['is_active'] = isset($_POST['is_active']) ? 1 : 0;

        $this->authConfigRepository->update($id, $data);
        $_SESSION['success_message'] = "Конфігурацію аутентифікації успішно оновлено.";
        return $this->redirectToRoute('admin_auth_configs');
    }

    #[Route('/auth-configs/delete', name: 'admin_auth_configs_delete', methods: ['POST'])]
    public function deleteAuthConfig() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $this->authConfigRepository->delete($id);
        $_SESSION['success_message'] = "Конфігурацію аутентифікації успішно видалено.";
        return $this->redirectToRoute('admin_auth_configs');
    }

    #[Route('/auth-configs/show', name: 'admin_auth_configs_show', methods: ['GET'])]
    public function showAuthConfig() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $config = $this->authConfigRepository->findById($id);

        if (! $config) {
            return new Response("Конфігурацію аутентифікації не знайдено", 404);
        }

        $redirectUri = $_ENV['APP_BASE_URL'] . '/oauth/callback/' . $config['provider'];

        return $this->render('admin/auth_configs/show.html.twig', [
            'config' => $config,
            'redirectUri' => $redirectUri,
        ]);
    }
}
