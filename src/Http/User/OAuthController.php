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
use App\Domain\User\UserOAuthIdentityRepository;
use App\Domain\User\UserRepository;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\Google;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class OAuthController extends AbstractController
{
    private AuthConfigRepository $authConfigRepository;
    private UserRepository $userRepository;
    private UserOAuthIdentityRepository $userOAuthIdentityRepository;
    private TokenStorageInterface $tokenStorage;

    public function __construct(
        AuthConfigRepository $authConfigRepository,
        UserRepository $userRepository,
        UserOAuthIdentityRepository $userOAuthIdentityRepository,
        TokenStorageInterface $tokenStorage
    ) {
        $this->authConfigRepository = $authConfigRepository;
        $this->userRepository = $userRepository;
        $this->userOAuthIdentityRepository = $userOAuthIdentityRepository;
        $this->tokenStorage = $tokenStorage;
    }

    public function redirectToProvider(string $provider, Request $request = null) : Response
    {
        $request ??= \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $providerConfig = $this->authConfigRepository->findByProvider($provider);

        if (! $providerConfig || ! $providerConfig['is_active']) {
            // Or handle this error more gracefully
            die("Провайдер не підтримується або вимкнений.");
        }

        $providerObj = $this->getProvider($provider, $providerConfig);

        $authUrl = $providerObj->getAuthorizationUrl();
        $request->getSession()->set('oauth2state', $providerObj->getState());

        return new RedirectResponse($authUrl);
    }

    #[Route('/oauth/callback/{provider}', name: 'oauth_callback', methods: ['GET'])]
    public function callback(string $provider, Request $request) : Response
    {
        $session = $request->getSession();
        $providerConfig = $this->authConfigRepository->findByProvider($provider);

        if (! $providerConfig || ! $providerConfig['is_active']) {
            die("Провайдер не підтримується або вимкнений.");
        }

        $providerObj = $this->getProvider($provider, $providerConfig);
        if (empty($_GET['state']) || ! $session->has('oauth2state') || ($_GET['state'] !== $session->get('oauth2state'))) {
            $session->remove('oauth2state');
            die('Некоректний стан запиту.');
        }

        try {
            $token = $providerObj->getAccessToken('authorization_code', [
                'code' => $_GET['code']
            ]);

            $ownerDetails = $providerObj->getResourceOwner($token);
            $providerId = $ownerDetails->getId();
            $email = $ownerDetails->getEmail();

            // 1. Check if user is already logged in (linking an existing account)
            $currentToken = $this->tokenStorage->getToken();
            if ($currentToken) {
                $currentUser = $currentToken->getUser();
                if ($currentUser instanceof \App\Domain\User\User) {
                    $userId = $currentUser->getId();

                    // Check if this provider is already linked to the current user
                    $existingIdentity = $this->userOAuthIdentityRepository->findByUserIdAndProvider($userId, $provider);

                    if ($existingIdentity) {
                        $session->set('info_message', sprintf('Ваш акаунт %s вже прив\'язано.', ucfirst($provider)));
                    } else {
                        // Check if this provider ID is already linked to ANOTHER user
                        $anotherUserIdentity = $this->userOAuthIdentityRepository->findByProviderAndProviderId($provider, $providerId);
                        if ($anotherUserIdentity && $anotherUserIdentity['user_id'] != $userId) {
                            $session->set('errors', ['oauth' => sprintf('Цей акаунт %s вже прив\'язано до іншого користувача.', ucfirst($provider))]);
                            return $this->redirectToRoute('user_profile');
                        }

                        // Link the account
                        $this->userOAuthIdentityRepository->create($userId, $provider, $providerId);
                        $session->set('success_message', sprintf('Ваш акаунт %s успішно прив\'язано.', ucfirst($provider)));
                    }

                    return $this->redirectToRoute('user_profile');
                }
            }

            // 2. User is not logged in - try to find or create user based on OAuth identity

            // Try to find a user by the OAuth identity
            $oauthIdentity = $this->userOAuthIdentityRepository->findByProviderAndProviderId($provider, $providerId);

            if ($oauthIdentity) {
                $user = $this->userRepository->findById($oauthIdentity['user_id']);
                if ($user) {
                    /** @var \App\Domain\User\User|null $userEntity */
                    $userEntity = $this->userRepository->find($user['id']);
                    if ($userEntity) {
                        $token = new UsernamePasswordToken($userEntity, 'main', $userEntity->getRoles());
                        $this->tokenStorage->setToken($token);
                    }

                    $session->set('user_id', $user['id']);
                    $redirect = $session->get('intended_url');
                    $session->remove('intended_url');
                    if ($redirect) {
                        return new RedirectResponse($redirect);
                    }

                    return $this->redirectToRoute('dashboard');
                }
            }

            // If no user found by OAuth identity, try to find by email
            $userByEmail = $this->userRepository->findByEmail($email);
            if ($userByEmail) {
                $this->userOAuthIdentityRepository->create($userByEmail['id'], $provider, $providerId);

                /** @var \App\Domain\User\User|null $userEntity */
                $userEntity = $this->userRepository->find($userByEmail['id']);
                if ($userEntity) {
                    $token = new UsernamePasswordToken($userEntity, 'main', $userEntity->getRoles());
                    $this->tokenStorage->setToken($token);
                }

                $session->set('user_id', $userByEmail['id']);
                $redirect = $session->get('intended_url');
                $session->remove('intended_url');
                if ($redirect) {
                    return new RedirectResponse($redirect);
                }

                return $this->redirectToRoute('dashboard');
            }

            // No user found or linked, redirect to login with an error or to a registration page
            $session->set('errors', ['login' => sprintf('Жодного користувача, пов\'язаного з цим акаунтом %s, не знайдено. Спершу зареєструйтеся або увійдіть в існуючий акаунт і прив\'яжіть його.', ucfirst($provider))]);
            return $this->redirectToRoute('login_form');
        } catch (IdentityProviderException $e) {
            $session->set('errors', ['oauth' => 'Помилка автентифікації: ' . $e->getMessage()]);
            return $this->redirectToRoute('login_form');
        }
    }

    private function getProvider(string $provider, array $config)
    {
        switch ($provider) {
            case 'google':
                return new Google([
                    'clientId'     => $config['client_id'],
                    'clientSecret' => $config['client_secret'],
                    'redirectUri'  => $_ENV['APP_BASE_URL'] . '/oauth/callback/google',
                ]);
            case 'facebook':
                return new Facebook([
                    'clientId'          => $config['client_id'],
                    'clientSecret'      => $config['client_secret'],
                    'redirectUri'       => $_ENV['APP_BASE_URL'] . '/oauth/callback/facebook',
                    'graphApiVersion'   => 'v2.10',
                ]);
            case 'github':
                return new Github([
                    'clientId'     => $config['client_id'],
                    'clientSecret' => $config['client_secret'],
                    'redirectUri'  => $_ENV['APP_BASE_URL'] . '/oauth/callback/github',
                ]);
            default:
                throw new \Exception("Провайдер не підтримується: $provider");
        }
    }

    public static function getSupportedProviders() : array
    {
        return [
            'google',
            'facebook',
            'github',
        ];
    }
}
