<?php

/*
 *
 *                      _
 *   _ __ ___   ___  __| | ___ ___  _ __ ___       _   _  __ _
 *  | '_ ` _ \ / _ \/ _` |/ __/ _ \| '__/ _ \_____| | | |/ _` |
 *  | | | | | |  __/ (_| | (_| (_) | | |  __/_____| |_| | (_| |
 *  |_| |_| |_|\___|\__,_|\___\___/|_|  \___|      \__,_|\__,_|
 *
 * This program is free software: you can redistribute it and/or modify
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

namespace App\Domain\LabOrder;

use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class LabOrderVoter extends Voter
{
    public const VIEW = 'LAB_ORDER_VIEW';
    public const VIEW_ANY = 'LAB_ORDER_VIEW_ANY';
    public const VIEW_OWN = 'LAB_ORDER_VIEW_OWN';
    public const CREATE = 'LAB_ORDER_CREATE';
    public const EDIT = 'LAB_ORDER_EDIT';
    public const EDIT_ANY = 'LAB_ORDER_EDIT_ANY';
    public const EDIT_OWN = 'LAB_ORDER_EDIT_OWN';

    private LabOrderRepository $labOrderRepository;
    private Security $security;

    public function __construct(
        LabOrderRepository $labOrderRepository,
        Security $security
    ) {
        $this->labOrderRepository = $labOrderRepository;
        $this->security = $security;
    }

    protected function supports(string $attribute, mixed $subject) : bool
    {
        return in_array($attribute, [
            self::VIEW,
            self::VIEW_ANY,
            self::VIEW_OWN,
            self::CREATE,
            self::EDIT,
            self::EDIT_ANY,
            self::EDIT_OWN,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token) : bool
    {
        $user = $token->getUser();
        if (! $user instanceof User) {
            return false;
        }

        // Administrators and Medical Managers have full access
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_MEDICAL_MANAGER')) {
            return true;
        }

        $labOrderId = $this->extractLabOrderId($subject);

        return match ($attribute) {
            self::VIEW => $this->canView($user, $labOrderId),
            self::VIEW_ANY => $this->security->isGranted('ROLE_LAB_ORDER_VIEW_ANY'),
            self::VIEW_OWN => $this->canViewOwn($user, $labOrderId),
            self::CREATE => $this->security->isGranted('ROLE_LAB_ORDER_CREATE'),
            self::EDIT => $this->canEdit($user, $labOrderId),
            self::EDIT_ANY => $this->security->isGranted('ROLE_LAB_ORDER_EDIT_ANY'),
            self::EDIT_OWN => $this->canEditOwn($user, $labOrderId),
            default => false,
        };
    }

    private function canView(User $user, ?int $labOrderId) : bool
    {
        if ($this->security->isGranted('ROLE_LAB_ORDER_VIEW_ANY')) {
            return true;
        }

        if ($labOrderId && $this->security->isGranted('ROLE_LAB_ORDER_VIEW_OWN')) {
            return $this->isOwner($user, $labOrderId);
        }

        return false;
    }

    private function canViewOwn(User $user, ?int $labOrderId) : bool
    {
        if (! $labOrderId) {
            return false;
        }

        return $this->isOwner($user, $labOrderId);
    }

    private function canEdit(User $user, ?int $labOrderId) : bool
    {
        if ($this->security->isGranted('ROLE_LAB_ORDER_EDIT_ANY')) {
            return true;
        }

        if ($labOrderId && $this->security->isGranted('ROLE_LAB_ORDER_EDIT_OWN')) {
            return $this->isOwner($user, $labOrderId);
        }

        return false;
    }

    private function canEditOwn(User $user, ?int $labOrderId) : bool
    {
        if (! $labOrderId) {
            return false;
        }

        return $this->isOwner($user, $labOrderId);
    }

    private function isOwner(User $user, int $labOrderId) : bool
    {
        $userId = $user->getId();
        if (! $userId) {
            return false;
        }

        $labOrder = $this->labOrderRepository->findById($labOrderId);
        return $labOrder && (int) $labOrder['doctor_id'] === $userId;
    }

    private function extractLabOrderId(mixed $subject) : ?int
    {
        if (is_int($subject) || is_string($subject)) {
            return (int) $subject;
        }

        if (is_array($subject) && isset($subject['id'])) {
            return (int) $subject['id'];
        }

        if (is_object($subject) && method_exists($subject, 'getId')) {
            return (int) $subject->getId();
        }

        return null;
    }
}
