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

namespace App\Auth;

use App\Domain\User\MfaService;
use App\Shared\Exception\RedirectException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class MfaGuard
{
    private MfaService $mfaService;
    private TokenStorageInterface $tokenStorage;
    private RequestStack $requestStack;

    public function __construct(MfaService $mfaService, TokenStorageInterface $tokenStorage, RequestStack $requestStack)
    {
        $this->mfaService = $mfaService;
        $this->tokenStorage = $tokenStorage;
        $this->requestStack = $requestStack;
    }

    private function session() : SessionInterface
    {
        return $this->requestStack->getSession();
    }

    public function check() : void
    {
        $step = AuthStep::current($this->tokenStorage, $this->session());
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        if ($step->requiresMfaVerify()) {
            $userId = $this->session()->get('mfa_pending_user_id');

            if ($this->mfaService->isMfaEnabled($userId)) {
                throw new RedirectException('/user/mfa/verify');
            } else {
                $this->clearPending();
            }
        } elseif ($step->requiresMfaSetup()) {
            if ($this->isRequired() && ! str_starts_with($requestUri, '/user/mfa/')) {
                throw new RedirectException('/user/mfa/required');
            }
        }
    }

    public function isPending() : bool
    {
        return $this->session()->has('mfa_pending_user_id');
    }

    public function getPendingUserId() : ?int
    {
        return $this->session()->get('mfa_pending_user_id');
    }

    public function clearPending() : void
    {
        $this->session()->remove('mfa_pending_user_id');
    }

    public function isRequired() : bool
    {
        return true === $this->session()->get('mfa_required');
    }

    public function setRequired() : void
    {
        $this->session()->set('mfa_required', true);
    }

    public function clearRequired() : void
    {
        $this->session()->remove('mfa_required');
    }

    public function getUserMfaType(int $userId) : ?string
    {
        $status = $this->mfaService->getUserMfaStatus($userId);
        return $status['type'] ?? null;
    }

    public function isHotpEnabled(int $userId) : bool
    {
        return 'hotp' === $this->getUserMfaType($userId);
    }
}
