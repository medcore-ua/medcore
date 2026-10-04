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

namespace App\Domain\Appointment;

use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class AppointmentVoter extends Voter
{
    public const VIEW = 'APPOINTMENT_VIEW';
    public const VIEW_ANY = 'APPOINTMENT_VIEW_ANY';
    public const VIEW_OWN = 'APPOINTMENT_VIEW_OWN';
    public const CREATE = 'APPOINTMENT_CREATE';
    public const EDIT = 'APPOINTMENT_EDIT';
    public const EDIT_ANY = 'APPOINTMENT_EDIT_ANY';
    public const EDIT_OWN = 'APPOINTMENT_EDIT_OWN';
    public const CANCEL = 'APPOINTMENT_CANCEL';
    public const CANCEL_ANY = 'APPOINTMENT_CANCEL_ANY';
    public const CANCEL_OWN = 'APPOINTMENT_CANCEL_OWN';

    private AppointmentRepository $appointmentRepository;
    private Security $security;

    public function __construct(
        AppointmentRepository $appointmentRepository,
        Security $security
    ) {
        $this->appointmentRepository = $appointmentRepository;
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
            self::CANCEL,
            self::CANCEL_ANY,
            self::CANCEL_OWN,
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

        $appointmentId = $this->extractAppointmentId($subject);

        return match ($attribute) {
            self::VIEW => $this->canView($user, $appointmentId),
            self::VIEW_ANY => $this->security->isGranted('ROLE_APPOINTMENT_VIEW_ANY'),
            self::VIEW_OWN => $this->canViewOwn($user, $appointmentId),
            self::CREATE => $this->security->isGranted('ROLE_APPOINTMENT_CREATE'),
            self::EDIT => $this->canEdit($user, $appointmentId),
            self::EDIT_ANY => $this->security->isGranted('ROLE_APPOINTMENT_EDIT_ANY'),
            self::EDIT_OWN => $this->canEditOwn($user, $appointmentId),
            self::CANCEL => $this->canCancel($user, $appointmentId),
            self::CANCEL_ANY => $this->security->isGranted('ROLE_APPOINTMENT_CANCEL_ANY'),
            self::CANCEL_OWN => $this->canCancelOwn($user, $appointmentId),
            default => false,
        };
    }

    private function canView(User $user, ?int $appointmentId) : bool
    {
        if ($this->security->isGranted('ROLE_APPOINTMENT_VIEW_ANY')) {
            return true;
        }

        if ($appointmentId && $this->security->isGranted('ROLE_APPOINTMENT_VIEW_OWN')) {
            return $this->isUserOwnerOfAppointment($user, $appointmentId);
        }

        return false;
    }

    private function canViewOwn(User $user, ?int $appointmentId) : bool
    {
        if (! $appointmentId) {
            return false;
        }

        return $this->isUserOwnerOfAppointment($user, $appointmentId);
    }

    private function canEdit(User $user, ?int $appointmentId) : bool
    {
        if ($this->security->isGranted('ROLE_APPOINTMENT_EDIT_ANY')) {
            return true;
        }

        if ($appointmentId && $this->security->isGranted('ROLE_APPOINTMENT_EDIT_OWN')) {
            return $this->isUserOwnerOfAppointment($user, $appointmentId);
        }

        return false;
    }

    private function canEditOwn(User $user, ?int $appointmentId) : bool
    {
        if (! $appointmentId) {
            return false;
        }

        return $this->isUserOwnerOfAppointment($user, $appointmentId);
    }

    private function canCancelOwn(User $user, ?int $appointmentId) : bool
    {
        if (! $appointmentId) {
            return false;
        }

        return $this->isUserOwnerOfAppointment($user, $appointmentId);
    }

    private function canCancel(User $user, ?int $appointmentId) : bool
    {
        if ($this->security->isGranted('ROLE_APPOINTMENT_CANCEL_ANY')) {
            return true;
        }

        if ($appointmentId && $this->security->isGranted('ROLE_APPOINTMENT_CANCEL_OWN')) {
            return $this->isUserOwnerOfAppointment($user, $appointmentId);
        }

        return false;
    }

    private function isUserOwnerOfAppointment(User $user, int $appointmentId) : bool
    {
        $userId = $user->getId();
        if (! $userId) {
            return false;
        }
        return $this->appointmentRepository->isAppointmentOwnedByDoctor($appointmentId, $userId);
    }

    private function extractAppointmentId(mixed $subject) : ?int
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
