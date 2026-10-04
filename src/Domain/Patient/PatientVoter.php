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

namespace App\Domain\Patient;

use App\Domain\Appointment\AppointmentRepository;
use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class PatientVoter extends Voter
{
    public const VIEW = 'PATIENT_VIEW';
    public const VIEW_ANY = 'PATIENT_VIEW_ANY';
    public const VIEW_OWN = 'PATIENT_VIEW_OWN';
    public const CREATE = 'PATIENT_CREATE';
    public const EDIT = 'PATIENT_EDIT';
    public const EDIT_ANY = 'PATIENT_EDIT_ANY';
    public const EDIT_OWN = 'PATIENT_EDIT_OWN';

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
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token) : bool
    {
        $user = $token->getUser();

        if (! $user instanceof User) {
            return false;
        }

        // Administrators can do anything with patients
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_MEDICAL_MANAGER')) {
            return true;
        }

        $patientId = $this->extractPatientId($subject);

        return match ($attribute) {
            self::VIEW => $this->canView($user, $patientId),
            self::VIEW_ANY => $this->security->isGranted('ROLE_PATIENT_VIEW_ANY'),
            self::VIEW_OWN => $this->canViewOwn($user, $patientId),
            self::CREATE => $this->security->isGranted('ROLE_PATIENT_CREATE'),
            self::EDIT => $this->canEdit($user, $patientId),
            self::EDIT_ANY => $this->security->isGranted('ROLE_PATIENT_EDIT_ANY'),
            self::EDIT_OWN => $this->canEditOwn($user, $patientId),
            default => false,
        };
    }

    private function canView(User $user, ?int $patientId) : bool
    {
        if ($this->security->isGranted('ROLE_PATIENT_VIEW_ANY')) {
            return true;
        }

        if ($patientId && $this->security->isGranted('ROLE_PATIENT_VIEW_OWN')) {
            return $this->isPatientAssignedToDoctor($patientId, $user);
        }

        return false;
    }

    private function canViewOwn(User $user, ?int $patientId) : bool
    {
        if (! $patientId) {
            return false;
        }

        return $this->isPatientAssignedToDoctor($patientId, $user);
    }

    private function canEdit(User $user, ?int $patientId) : bool
    {
        if ($this->security->isGranted('ROLE_PATIENT_EDIT_ANY')) {
            return true;
        }

        if ($patientId && $this->security->isGranted('ROLE_PATIENT_EDIT_OWN')) {
            return $this->isPatientAssignedToDoctor($patientId, $user);
        }

        return false;
    }

    private function canEditOwn(User $user, ?int $patientId) : bool
    {
        if (! $patientId) {
            return false;
        }

        return $this->isPatientAssignedToDoctor($patientId, $user);
    }

    private function isPatientAssignedToDoctor(int $patientId, User $user) : bool
    {
        $userId = $user->getId();
        if (! $userId) {
            return false;
        }

        return $this->appointmentRepository->isPatientAssignedToDoctor($patientId, $userId);
    }

    private function extractPatientId(mixed $subject) : ?int
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
