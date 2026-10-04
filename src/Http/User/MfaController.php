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

use App\Auth\MfaGuard;
use App\Domain\User\MfaService;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Shared\Repository\SettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MfaController extends AbstractController
{
    private MfaService $mfaService;
    private UserRepository $userRepository;
    private SettingsRepository $settingsRepository;
    private \Doctrine\Persistence\ManagerRegistry $registry;
    private MfaGuard $mfaGuard;
    private TokenStorageInterface $tokenStorage;

    public function __construct(
        MfaService $mfaService,
        UserRepository $userRepository,
        SettingsRepository $settingsRepository,
        \Doctrine\Persistence\ManagerRegistry $registry,
        MfaGuard $mfaGuard,
        TokenStorageInterface $tokenStorage
    ) {
        $this->mfaService = $mfaService;
        $this->userRepository = $userRepository;
        $this->settingsRepository = $settingsRepository;
        $this->registry = $registry;
        $this->mfaGuard = $mfaGuard;
        $this->tokenStorage = $tokenStorage;
    }

    private function authenticateUser(int $userId, SessionInterface $session) : void
    {
        /** @var \App\Domain\User\User|null $userEntity */
        $userEntity = $this->userRepository->find($userId);
        if ($userEntity) {
            $token = new UsernamePasswordToken($userEntity, 'main', $userEntity->getRoles());
            $this->tokenStorage->setToken($token);
        }
        $session->set('user_id', $userId);
    }

    private function prepareHotpSetup(int $userId, array &$secret, array &$backupCodes, int &$counter, string &$qrCode, SessionInterface $session) : void
    {
        $secret = $session->get('hotp_setup_secret') ?? $this->mfaService->generateHotpSecret();
        $counter = $session->get('hotp_setup_counter') ?? 0;
        $backupCodes = $session->get('hotp_setup_backup_codes') ?? $this->mfaService->generateBackupCodes();

        $user = $this->userRepository->findById($userId);
        $qrCode = $this->mfaService->generateHotpQRCode($secret, $user['email'], $counter);

        $session->set('hotp_setup_secret', $secret);
        $session->set('hotp_setup_counter', $counter);
        $session->set('hotp_setup_backup_codes', $backupCodes);
    }

    private function prepareTotpSetup(int $userId, string &$secret, array &$backupCodes, string &$qrCode, SessionInterface $session) : void
    {
        $secret = $this->mfaService->generateSecret();
        $user = $this->userRepository->findById($userId);
        $qrCode = $this->mfaService->generateQRCode($secret, $user['email']);
        $backupCodes = $this->mfaService->generateBackupCodes();

        $session->set('mfa_setup_secret', $secret);
        $session->set('mfa_setup_backup_codes', $backupCodes);
    }

    #[Route('/user/mfa/setup/{type}', name: 'mfa_setup', methods: ['GET'], defaults: ['type' => 'totp'])]
    public function showMfaSetup(Request $request, string $type = 'totp', #[CurrentUser] ?User $currentUser = null) : Response
    {
        if (! in_array($type, ['totp', 'hotp'], true)) {
            $type = 'totp';
        }

        $session = $request->getSession();

        if ($currentUser) {
            $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

            $mfaPolicy = $this->settingsRepository->getMfaPolicy();

            if ('disabled' === $mfaPolicy) {
                $session->set('error_message', 'Двофакторна автентифікація вимкнена в налаштуваннях системи.');
                return $this->redirectToRoute('user_profile');
            }
        }

        $userId = $session->get('mfa_pending_user_id') ?? ($currentUser?->getId());

        if (! $userId) {
            return $this->redirectToRoute('login_form');
        }

        $user = $this->userRepository->findById($userId);

        if (! $user) {
            $session->invalidate();
            return $this->redirectToRoute('login_form');
        }

        $isReset = isset($_GET['reset']) && '1' === $_GET['reset'];

        if ($this->mfaService->isMfaEnabled($userId) && ! $isReset) {
            return $this->redirectToRoute('user_profile');
        }

        if ('hotp' === $type) {
            $secret = [];
            $backupCodes = [];
            $counter = 0;
            $qrCode = '';
            $this->prepareHotpSetup($userId, $secret, $backupCodes, $counter, $qrCode, $session);

            return $this->render('user/hotp_setup.html.twig', [
                'user' => $user,
                'secret' => $secret,
                'qrCode' => $qrCode,
                'backupCodes' => $backupCodes,
                'counter' => $counter,
                'isReset' => $isReset,
            ]);
        } else {
            $secret = '';
            $backupCodes = [];
            $qrCode = '';
            $this->prepareTotpSetup($userId, $secret, $backupCodes, $qrCode, $session);
            $session->set('mfa_setup_is_reset', $isReset);

            return $this->render('user/mfa_setup.html.twig', [
                'user' => $user,
                'secret' => $secret,
                'qrCode' => $qrCode,
                'backupCodes' => $backupCodes,
                'isReset' => $isReset,
            ]);
        }
    }

    #[Route('/user/mfa/required', name: 'mfa_required_choice', methods: ['GET'])]
    public function showMfaRequiredChoice(Request $request) : Response
    {
        $session = $request->getSession();
        $userId = $session->get('mfa_pending_user_id');

        if (! $userId) {
            return $this->redirectToRoute('login_form');
        }

        $user = $this->userRepository->findById($userId);

        if (! $user) {
            $session->invalidate();
            return $this->redirectToRoute('login_form');
        }

        return $this->render('user/mfa_required_choice.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/user/mfa/required/{type}', name: 'mfa_required', methods: ['GET'], defaults: ['type' => 'totp'])]
    public function showMfaRequired(Request $request, string $type = 'totp') : Response
    {
        if (! in_array($type, ['totp', 'hotp'], true)) {
            return $this->redirectToRoute('mfa_required_choice');
        }

        $session = $request->getSession();
        $userId = $session->get('mfa_pending_user_id');

        if (! $userId) {
            return $this->redirectToRoute('login_form');
        }

        $user = $this->userRepository->findById($userId);

        if (! $user) {
            $session->invalidate();
            return $this->redirectToRoute('login_form');
        }

        if ('hotp' === $type) {
            $secret = $session->get('hotp_setup_secret');
            $backupCodes = $session->get('hotp_setup_backup_codes', []);

            if (! $secret || ! $backupCodes) {
                $secret = [];
                $counter = 0;
                $qrCode = '';
                $this->prepareHotpSetup($userId, $secret, $backupCodes, $counter, $qrCode, $session);
            } else {
                $counter = $session->get('hotp_setup_counter', 0);
                $qrCode = $this->mfaService->generateHotpQRCode($secret, $user['email'], $counter);
            }

            return $this->render('user/hotp_required.html.twig', [
                'user' => $user,
                'secret' => $secret,
                'qrCode' => $qrCode,
                'backupCodes' => $backupCodes,
                'counter' => $counter,
            ]);
        } else {
            $secret = $session->get('mfa_setup_secret');
            $backupCodes = $session->get('mfa_setup_backup_codes', []);

            if (! $secret || ! $backupCodes) {
                $secret = '';
                $qrCode = '';
                $this->prepareTotpSetup($userId, $secret, $backupCodes, $qrCode, $session);
            } else {
                $qrCode = $this->mfaService->generateQRCode($secret, $user['email']);
            }

            return $this->render('user/totp_required.html.twig', [
                'user' => $user,
                'secret' => $secret,
                'qrCode' => $qrCode,
                'backupCodes' => $backupCodes,
            ]);
        }
    }

    #[Route('/user/mfa/setup/{type}', name: 'mfa_setup_verify', methods: ['POST'], defaults: ['type' => 'totp'])]
    public function verifyMfaSetup(Request $request, string $type = 'totp', #[CurrentUser] ?User $currentUser = null) : Response
    {
        if (! in_array($type, ['totp', 'hotp'], true)) {
            $type = 'totp';
        }

        $session = $request->getSession();

        if ($currentUser) {
            $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

            $mfaPolicy = $this->settingsRepository->getMfaPolicy();

            if ('disabled' === $mfaPolicy) {
                $session->set('error_message', 'Двофакторна автентифікація вимкнена в налаштуваннях системи.');
                return $this->redirectToRoute('user_profile');
            }
        }

        $userId = $session->get('mfa_pending_user_id') ?? ($currentUser?->getId());

        if (! $userId) {
            return $this->redirectToRoute('login_form');
        }

        $isReset = isset($_GET['reset']) && '1' === $_GET['reset'];

        if ('hotp' === $type) {
            $secret = $session->get('hotp_setup_secret');
            $backupCodes = $session->get('hotp_setup_backup_codes', []);
            $counter = $session->get('hotp_setup_counter', 0);

            if (! $secret) {
                return $this->redirectToRoute('mfa_setup', ['type' => 'hotp', 'reset' => 1]);
            }

            $code = $_POST['code'] ?? '';

            if (empty($code)) {
                $session->set('error_message', 'Будь ласка, введіть код.');
                return $this->redirectToRoute('mfa_setup', ['type' => 'hotp', 'reset' => 1]);
            }

            $lastCounter = $session->get('hotp_setup_last_counter', 0);
            $verifiedCounter = $this->mfaService->verifyHotpCodeWithCounter($secret, $code, $counter, $lastCounter);

            if (null !== $verifiedCounter) {
                $session->set('hotp_setup_last_counter', $verifiedCounter);
                $this->mfaService->enableHotpForUser($userId, $secret, $backupCodes, $verifiedCounter + 1);

                $session->remove('hotp_setup_secret');
                $session->remove('hotp_setup_backup_codes');
                $session->remove('hotp_setup_counter');
                $session->remove('hotp_setup_last_counter');

                if ($this->getUser()) {
                    $session->set('success_message', 'Двофакторну автентифікацію HOTP успішно увімкнено!');
                    return $this->redirectToRoute('user_profile');
                } else {
                    $this->mfaGuard->clearRequired();
                    $this->authenticateUser($userId, $session);

                    $redirect = $session->get('intended_url');
                    $session->remove('intended_url');
                    if ($redirect) {
                        return new RedirectResponse($redirect);
                    }

                    return $this->redirectToRoute('dashboard');
                }
            } else {
                $session->set('error_message', 'Невірний код. Спробуйте ще раз.');
                return $this->redirectToRoute('mfa_setup', ['type' => 'hotp', 'reset' => 1]);
            }
        } else {
            $secret = $session->get('mfa_setup_secret');
            $backupCodes = $session->get('mfa_setup_backup_codes', []);

            if (! $secret) {
                return $this->redirectToRoute('mfa_setup', ['type' => 'hotp', 'reset' => 1]);
            }

            $code = $_POST['code'] ?? '';

            if (empty($code)) {
                $session->set('error_message', 'Будь ласка, введіть код з додатку.');
                return $this->redirectToRoute('mfa_setup', ['type' => 'hotp', 'reset' => 1]);
            }

            if ($this->mfaService->verifyCode($secret, $code)) {
                $this->mfaService->enableMfaForUser($userId, $secret, $backupCodes, 'totp');

                $session->remove('mfa_setup_secret');
                $session->remove('mfa_setup_backup_codes');

                if ($this->getUser()) {
                    $session->set('success_message', 'Двофакторну автентифікацію успішно увімкнено!');
                    return $this->redirectToRoute('user_profile');
                } else {
                    $this->mfaGuard->clearRequired();
                    $this->authenticateUser($userId, $session);

                    $redirect = $session->get('intended_url');
                    $session->remove('intended_url');
                    if ($redirect) {
                        return new RedirectResponse($redirect);
                    }

                    return $this->redirectToRoute('dashboard');
                }
            } else {
                $session->set('error_message', 'Невірний код. Спробуйте ще раз.');
                return $this->redirectToRoute('mfa_setup', ['type' => 'hotp', 'reset' => 1]);
            }
        }
    }

    #[Route('/user/mfa/required/{type}', name: 'mfa_required_verify', methods: ['POST'], defaults: ['type' => 'totp'])]
    public function verifyMfaRequired(Request $request, string $type = 'totp') : Response
    {
        if (! in_array($type, ['totp', 'hotp'], true)) {
            return $this->redirectToRoute('mfa_required_choice');
        }

        $session = $request->getSession();
        $userId = $session->get('mfa_pending_user_id');

        if (! $userId) {
            return $this->redirectToRoute('login_form');
        }

        if ('hotp' === $type) {
            $secret = $session->get('hotp_setup_secret');
            $backupCodes = $session->get('hotp_setup_backup_codes', []);
            $counter = $session->get('hotp_setup_counter', 0);

            if (! $secret) {
                $session->set('error_message', 'Помилка генерування коду. Спробуйте ще раз.');
                return $this->redirectToRoute('mfa_required', ['type' => 'hotp']);
            }

            $code = $_POST['code'] ?? '';

            if (empty($code)) {
                $session->set('error_message', 'Будь ласка, введіть код.');
                return $this->redirectToRoute('mfa_required', ['type' => 'hotp']);
            }

            $lastCounter = $session->get('hotp_setup_last_counter', 0);
            $verifiedCounter = $this->mfaService->verifyHotpCodeWithCounter($secret, $code, $counter, $lastCounter);

            if (null !== $verifiedCounter) {
                $session->set('hotp_setup_last_counter', $verifiedCounter);
                $this->mfaService->enableHotpForUser($userId, $secret, $backupCodes, $verifiedCounter + 1);

                $session->remove('hotp_setup_secret');
                $session->remove('hotp_setup_backup_codes');
                $session->remove('hotp_setup_counter');
                $session->remove('hotp_setup_last_counter');
                $this->mfaGuard->clearRequired();

                $this->authenticateUser($userId, $session);

                $redirect = $session->get('intended_url');
                $session->remove('intended_url');
                if ($redirect) {
                    return new RedirectResponse($redirect);
                }

                return $this->redirectToRoute('dashboard');
            } else {
                $session->set('error_message', 'Невірний код. Спробуйте ще раз.');
                return $this->redirectToRoute('mfa_required', ['type' => 'hotp']);
            }
        } else {
            $secret = $session->get('mfa_setup_secret');
            $backupCodes = $session->get('mfa_setup_backup_codes', []);

            if (! $secret) {
                $session->set('error_message', 'Помилка генерування коду. Спробуйте ще раз.');
                return $this->redirectToRoute('mfa_required', ['type' => 'totp']);
            }

            $code = $_POST['code'] ?? '';

            if (empty($code)) {
                $session->set('error_message', 'Будь ласка, введіть код з додатку.');
                return $this->redirectToRoute('mfa_required', ['type' => 'totp']);
            }

            if ($this->mfaService->verifyCode($secret, $code)) {
                $this->mfaService->enableMfaForUser($userId, $secret, $backupCodes, 'totp');

                $session->remove('mfa_setup_secret');
                $session->remove('mfa_setup_backup_codes');
                $this->mfaGuard->clearRequired();

                $this->authenticateUser($userId, $session);

                $redirect = $session->get('intended_url');
                $session->remove('intended_url');
                if ($redirect) {
                    return new RedirectResponse($redirect);
                }

                return $this->redirectToRoute('dashboard');
            } else {
                $session->set('error_message', 'Невірний код. Спробуйте ще раз.');
                return $this->redirectToRoute('mfa_required', ['type' => 'totp']);
            }
        }
    }

    #[Route('/user/mfa/disable', name: 'mfa_disable', methods: ['POST'])]
    public function disableMfa(Request $request, #[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $session = $request->getSession();
        $userId = $user->getId();
        $password = $_POST['password'] ?? '';

        $user = $this->userRepository->findById($userId);

        if (! $user || ! password_verify($password, $user['password_hash'])) {
            $session->set('error_message', 'Невірний пароль.');
            return $this->redirectToRoute('user_profile');
        }

        $this->mfaService->disableMfaForUser($userId);

        $session->set('success_message', 'Двофакторну автентифікацію вимкнено.');
        return $this->redirectToRoute('user_profile');
    }

    #[Route('/user/mfa/verify', name: 'mfa_verify', methods: ['GET'])]
    public function showMfaVerify(Request $request) : Response
    {
        $session = $request->getSession();
        $userId = $session->get('mfa_pending_user_id');

        if (! $userId) {
            return $this->redirectToRoute('login_form');
        }

        $user = $this->userRepository->findById($userId);

        if (! $user) {
            $session->invalidate();
            return $this->redirectToRoute('login_form');
        }

        $errorMessage = $session->get('mfa_error');
        $session->remove('mfa_error');

        $mfaType = $session->get('mfa_type') ?? $this->mfaService->getUserMfaStatus($userId)['type'] ?? 'totp';

        return $this->render('user/mfa_verify.html.twig', [
            'user' => $user,
            'errorMessage' => $errorMessage,
            'mfaType' => $mfaType,
        ]);
    }

    #[Route('/user/mfa/verify', name: 'mfa_verify_post', methods: ['POST'])]
    public function verifyMfa(Request $request) : Response
    {
        $session = $request->getSession();
        $userId = $session->get('mfa_pending_user_id');

        if (! $userId) {
            return $this->redirectToRoute('login_form');
        }

        $code = $_POST['code'] ?? '';

        if (empty($code)) {
            $session->set('mfa_error', 'Будь ласка, введіть код.');
            return $this->redirectToRoute('mfa_verify');
        }

        if ($this->mfaService->verifyUserMfa($userId, $code)) {
            $session->remove('mfa_pending_user_id');
            $this->mfaGuard->clearRequired();

            $this->authenticateUser($userId, $session);

            $redirect = $session->get('intended_url');
            $session->remove('intended_url');
            if ($redirect) {
                return new RedirectResponse($redirect);
            }

            return $this->redirectToRoute('dashboard');
        } else {
            $session->set('mfa_error', 'Невірний код. Спробуйте ще раз.');
            return $this->redirectToRoute('mfa_verify');
        }
    }

    #[Route('/user/mfa/regenerate-backup-codes', name: 'mfa_regenerate_backup_codes', methods: ['POST'])]
    public function regenerateBackupCodes(Request $request, #[CurrentUser] User $user) : Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $session = $request->getSession();
        $userId = $user->getId();
        $password = $_POST['password'] ?? '';

        $user = $this->userRepository->findById($userId);

        if (! $user || ! password_verify($password, $user['password_hash'])) {
            $session->set('error_message', 'Невірний пароль.');
            return $this->redirectToRoute('user_profile');
        }

        if (! $this->mfaService->isMfaEnabled($userId)) {
            return $this->redirectToRoute('user_profile');
        }

        $backupCodes = $this->mfaService->generateBackupCodes();

        $conn = $this->registry->getConnection();
        $conn->executeStatement("
            UPDATE users SET mfa_backup_codes = :codes WHERE id = :id
        ", ['id' => $userId, 'codes' => json_encode($backupCodes)]);

        $session->set('new_backup_codes', $backupCodes);
        return $this->redirectToRoute('user_profile');
    }

    #[Route('/user/mfa/clear-backup-codes', name: 'mfa_clear_backup_codes', methods: ['GET', 'POST'])]
    public function clearNewBackupCodes(Request $request) : Response
    {
        $request->getSession()->remove('new_backup_codes');
        return $this->redirectToRoute('user_profile');
    }
}
