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

namespace App\Http\User;

use App\Domain\Admin\AuthConfigRepository;
use App\Domain\Hrm\HrmRepository;
use App\Domain\User\User;
use App\Domain\User\UserOAuthIdentityRepository;
use App\Domain\User\UserRepository;
use App\Shared\Repository\SettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class UserController extends AbstractController
{
    private UserRepository $userRepository;
    private AuthConfigRepository $authConfigRepository;
    private UserOAuthIdentityRepository $userOAuthIdentityRepository;
    private HrmRepository $hrmRepository;
    private SettingsRepository $settingsRepository;

    public function __construct(
        UserRepository $userRepository,
        AuthConfigRepository $authConfigRepository,
        UserOAuthIdentityRepository $userOAuthIdentityRepository,
        HrmRepository $hrmRepository,
        SettingsRepository $settingsRepository
    ) {
        $this->userRepository = $userRepository;
        $this->authConfigRepository = $authConfigRepository;
        $this->userOAuthIdentityRepository = $userOAuthIdentityRepository;
        $this->hrmRepository = $hrmRepository;
        $this->settingsRepository = $settingsRepository;
    }

    #[Route('/user/profile', name: 'user_profile', methods: ['GET'])]
    public function profile(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->userRepository->findById($user->getId());

        if (! $user) {
            session_destroy();
            return $this->redirectToRoute('login_form');
        }

        $employee = $this->hrmRepository->findByUserId($user['id']);

        $successMessage = $_SESSION['success_message'] ?? null;
        unset($_SESSION['success_message']);

        $linkedProviders = $this->userOAuthIdentityRepository->findAllByUserId($user['id']);

        $mfaPolicy = $this->settingsRepository->getMfaPolicy();

        return $this->render('user/profile.html.twig', [
            'user' => $user,
            'employee' => $employee,
            'successMessage' => $successMessage,
            'authConfigs' => $this->authConfigRepository->findActive(),
            'linkedProviders' => array_column($linkedProviders, 'provider'),
            'mfaPolicy' => $mfaPolicy,
        ]);
    }

    #[Route('/user/profile/unlink-provider/{provider}', name: 'user_profile_unlink', methods: ['POST'])]
    public function unlinkProvider(#[CurrentUser] User $user, string $provider) : Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $userId = $user->getId();
        $this->userOAuthIdentityRepository->deleteByUserIdAndProvider($userId, $provider);

        $_SESSION['success_message'] = sprintf('Ваш акаунт %s було успішно відв\'язано.', ucfirst($provider));
        return $this->redirectToRoute('user_profile');
    }

    #[Route('/user/profile/upload-photo', name: 'user_profile_photo', methods: ['POST'])]
    public function uploadPhoto(#[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $userId = $user->getId();
        $uploadDir = __DIR__ . '/../../../public/uploads/avatars/';

        // Create directory if it doesn't exist
        if (! is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        if (empty($_FILES['profile_photo']) || UPLOAD_ERR_OK !== $_FILES['profile_photo']['error']) {
            $_SESSION['error_message'] = 'Будь ласка, виберіть файл для завантаження.';
            return $this->redirectToRoute('user_profile');
        }

        $file = $_FILES['profile_photo'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxFileSize = 2 * 1024 * 1024; // 2MB

        if (! in_array($file['type'], $allowedTypes)) {
            $_SESSION['error_message'] = 'Дозволені лише файли зображень (JPG, PNG, GIF).';
            return $this->redirectToRoute('user_profile');
        }

        if ($file['size'] > $maxFileSize) {
            $_SESSION['error_message'] = 'Розмір файлу не повинен перевищувати 2MB.';
            return $this->redirectToRoute('user_profile');
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = uniqid('avatar_') . '.' . $extension;
        $filePath = $uploadDir . $fileName;
        $relativePath = '/uploads/avatars/' . $fileName;

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            // Update user's profile photo path in the database
            $this->userRepository->updateProfilePhotoPath($userId, $relativePath);
            $_SESSION['success_message'] = 'Фото профілю успішно завантажено.';
        } else {
            $_SESSION['error_message'] = 'Не вдалося завантажити файл. Спробуйте ще раз.';
        }

        return $this->redirectToRoute('user_profile');
    }

    #[Route('/user/clear-messages', name: 'user_messages_clear', methods: ['POST'])]
    public function clearMessages() : Response
    {
        unset($_SESSION['success_message'], $_SESSION['error_message']);
        return new Response('', 200);
    }
}
