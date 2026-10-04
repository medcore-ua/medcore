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
use App\Domain\User\MfaService;
use App\Domain\User\RoleRepository;
use App\Domain\User\UserRepository;
use App\Event\UserLoggedInEvent;
use App\Event\UserLoggedOutEvent;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class AuthController extends AbstractController
{
    private UserRepository $userRepository;
    private AuthConfigRepository $authConfigRepository;
    private RoleRepository $roleRepository;
    private MfaService $mfaService;
    private OAuthController $oauthController;
    private \App\Shared\Repository\SettingsRepository $settingsRepository;
    private Validator $validator;
    private EventDispatcherInterface $eventDispatcher;
    private TokenStorageInterface $tokenStorage;

    public function __construct(
        UserRepository $userRepository,
        AuthConfigRepository $authConfigRepository,
        RoleRepository $roleRepository,
        MfaService $mfaService,
        \App\Shared\Repository\SettingsRepository $settingsRepository,
        OAuthController $oauthController,
        Validator $validator,
        EventDispatcherInterface $eventDispatcher,
        TokenStorageInterface $tokenStorage
    ) {
        $this->userRepository = $userRepository;
        $this->authConfigRepository = $authConfigRepository;
        $this->roleRepository = $roleRepository;
        $this->mfaService = $mfaService;
        $this->settingsRepository = $settingsRepository;
        $this->oauthController = $oauthController;
        $this->validator = $validator;
        $this->eventDispatcher = $eventDispatcher;
        $this->tokenStorage = $tokenStorage;
    }

    #[Route('/login', name: 'login_form', methods: ['GET'])]
    public function showLoginForm(Request $request) : Response
    {
        $session = $request->getSession();
        $old = $session->get('old', []);
        $session->remove('old');
        $errors = $session->get('errors', []);
        $session->remove('errors');

        return $this->render('user/login.html.twig', [
            'old' => $old,
            'errors' => [
                'login' => $errors['login'] ?? null,
                'email' => $errors['email'] ?? null,
                'password' => $errors['password'] ?? null,
            ],
            'authConfigs' => $this->authConfigRepository->findActive(),
        ]);
    }

    #[Route('/login', name: 'login_post', methods: ['POST'])]
    public function login(Request $request) : Response
    {
        // Admin creation should be done via CLI commands or fixtures in Symfony

        $session = $request->getSession();
        $validator = $this->validator;
        $validator->validate($_POST, [
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if ($validator->hasErrors()) {
            $session->set('errors', $validator->getErrors());
            $session->set('old', $_POST);
            return $this->redirectToRoute('login_form');
        }

        $email = $_POST['email'];
        $password = $_POST['password'];

        $user = $this->userRepository->findByEmail($email);
        $role = $user ? $this->roleRepository->findById((int)$user['role_id']) : null;

        if ($user && password_verify($password, $user['password_hash'])) {
            /** @var \App\Domain\User\User|null $userEntity */
            $userEntity = $this->userRepository->find((int) $user['id']);

            $mfaService = $this->mfaService;
            $mfaPolicy = $this->settingsRepository->getMfaPolicy();
            $mfaForceRoles = $this->settingsRepository->getMfaForceRoles();

            if ('disabled' === $mfaPolicy) {
                if ($userEntity) {
                    $token = new UsernamePasswordToken($userEntity, 'main', $userEntity->getRoles());
                    $this->tokenStorage->setToken($token);
                }
                $session->set('user_id', $user['id']);
                $this->eventDispatcher->dispatch(new UserLoggedInEvent($user['id'], $user['email']));
                $redirect = $session->get('intended_url');
                $session->remove('intended_url');
                if ($redirect) {
                    return new RedirectResponse($redirect);
                }

                return $this->redirectToRoute('dashboard');
            }

            $roleRequiresMfa = in_array((int)$user['role_id'], $mfaForceRoles, true);

            if ($roleRequiresMfa && ! $mfaService->isMfaEnabled($user['id'])) {
                $session->set('mfa_required', true);
                $session->set('mfa_required_type', 'totp');
                $session->set('mfa_pending_user_id', $user['id']);
                $intendedUrl = $session->get('intended_url');
                $session->remove('intended_url');
                if ($intendedUrl) {
                    $session->set('intended_url', $intendedUrl);
                }
                return $this->redirectToRoute('mfa_required_choice');
            }

            if ($mfaService->isMfaEnabled($user['id'])) {
                $session->set('mfa_pending_user_id', $user['id']);
                $session->set('mfa_type', $mfaService->getUserMfaStatus($user['id'])['type']);
                $intendedUrl = $session->get('intended_url');
                $session->remove('intended_url');
                if ($intendedUrl) {
                    $session->set('intended_url', $intendedUrl);
                }
                return $this->redirectToRoute('mfa_verify');
            }

            if ($userEntity) {
                $token = new UsernamePasswordToken($userEntity, 'main', $userEntity->getRoles());
                $this->tokenStorage->setToken($token);
            }
            $session->set('user_id', $user['id']);
            $this->eventDispatcher->dispatch(new UserLoggedInEvent($user['id'], $user['email']));
            $redirect = $session->get('intended_url');
            $session->remove('intended_url');
            if ($redirect) {
                return new RedirectResponse($redirect);
            }

            return $this->redirectToRoute('dashboard');
        } else {
            $session->set('errors', ['login' => 'Невірний email або пароль.']);
            $session->set('old', $_POST);
            return $this->redirectToRoute('login_form');
        }
    }

    /**
     * Redirects to the specified OAuth provider for authentication.
     *
     * @param  string   $provider
     * @return Response
     */
    #[Route('/oauth/redirect/{provider}', name: 'oauth_login', methods: ['GET'])]
    public function redirectToProvider(string $provider) : Response
    {
        return $this->oauthController->redirectToProvider($provider);
    }

    #[Route('/logout', name: 'logout', methods: ['GET', 'POST'])]
    public function logout(Request $request) : Response
    {
        $token = $this->tokenStorage->getToken();
        if ($token) {
            $user = $token->getUser();
            if ($user instanceof \App\Domain\User\User) {
                $this->eventDispatcher->dispatch(new UserLoggedOutEvent($user->getId(), $user->getEmail()));
            }
        }
        $this->tokenStorage->setToken(null);
        $request->getSession()->invalidate();
        return $this->redirectToRoute('login_form');
    }

    #[Route('/dashboard-redirect', name: 'dashboard_redirect', methods: ['GET'])]
    public function dashboard() : Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        return $this->redirectToRoute('dashboard');
    }
}
