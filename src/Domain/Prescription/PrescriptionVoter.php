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

namespace App\Domain\Prescription;

use App\Domain\Appointment\AppointmentRepository;
use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class PrescriptionVoter extends Voter
{
    public const VIEW = 'PRESCRIPTION_VIEW';
    public const VIEW_ANY = 'PRESCRIPTION_VIEW_ANY';
    public const VIEW_OWN = 'PRESCRIPTION_VIEW_OWN';
    public const CREATE = 'PRESCRIPTION_CREATE';
    public const CREATE_ANY = 'PRESCRIPTION_CREATE_ANY';
    public const CREATE_OWN = 'PRESCRIPTION_CREATE_OWN';
    public const EDIT = 'PRESCRIPTION_EDIT';
    public const EDIT_ANY = 'PRESCRIPTION_EDIT_ANY';
    public const EDIT_OWN = 'PRESCRIPTION_EDIT_OWN';

    private PrescriptionRepository $prescriptionRepository;
    private AppointmentRepository $appointmentRepository;
    private Security $security;

    public function __construct(
        PrescriptionRepository $prescriptionRepository,
        AppointmentRepository $appointmentRepository,
        Security $security
    ) {
        $this->prescriptionRepository = $prescriptionRepository;
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
            self::CREATE_ANY,
            self::CREATE_OWN,
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

        // Administrators and Medical Managers have full access to view and manage prescriptions
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_MEDICAL_MANAGER')) {
            return true;
        }

        $prescriptionId = $this->extractPrescriptionId($subject);

        return match ($attribute) {
            self::VIEW => $this->canView($user, $prescriptionId),
            self::VIEW_ANY => $this->security->isGranted('ROLE_PRESCRIPTION_VIEW_ANY'),
            self::VIEW_OWN => $this->canViewOwn($user, $prescriptionId),
            self::CREATE => $this->canCreate($user, $subject),
            self::CREATE_ANY => $this->security->isGranted('ROLE_PRESCRIPTION_CREATE_ANY'),
            self::CREATE_OWN => $this->canCreateOwn($user, $subject),
            self::EDIT => $this->canEdit($user, $prescriptionId),
            self::EDIT_ANY => $this->security->isGranted('ROLE_PRESCRIPTION_EDIT_ANY'),
            self::EDIT_OWN => $this->canEditOwn($user, $prescriptionId),
            default => false,
        };
    }

    private function canView(User $user, ?int $prescriptionId) : bool
    {
        if ($this->security->isGranted('ROLE_PRESCRIPTION_VIEW_ANY')) {
            return true;
        }

        if ($prescriptionId && $this->security->isGranted('ROLE_PRESCRIPTION_VIEW_OWN')) {
            return $this->isOwner($user, $prescriptionId);
        }

        return false;
    }

    private function canViewOwn(User $user, ?int $prescriptionId) : bool
    {
        if (! $prescriptionId) {
            return false;
        }

        return $this->isOwner($user, $prescriptionId);
    }

    private function canCreate(User $user, mixed $subject) : bool
    {
        if ($this->security->isGranted('ROLE_PRESCRIPTION_CREATE_ANY')) {
            return true;
        }

        if ($this->security->isGranted('ROLE_PRESCRIPTION_CREATE_OWN')) {
            return $this->isDoctorCreatingForSelf($user, $subject);
        }

        return false;
    }

    private function canCreateOwn(User $user, mixed $subject) : bool
    {
        return $this->isDoctorCreatingForSelf($user, $subject);
    }

    private function canEdit(User $user, ?int $prescriptionId) : bool
    {
        if ($this->security->isGranted('ROLE_PRESCRIPTION_EDIT_ANY')) {
            return true;
        }

        if ($prescriptionId && $this->security->isGranted('ROLE_PRESCRIPTION_EDIT_OWN')) {
            return $this->isOwner($user, $prescriptionId);
        }

        return false;
    }

    private function canEditOwn(User $user, ?int $prescriptionId) : bool
    {
        if (! $prescriptionId) {
            return false;
        }

        return $this->isOwner($user, $prescriptionId);
    }

    private function isDoctorCreatingForSelf(User $user, mixed $subject) : bool
    {
        $context = is_array($subject) ? $subject : [];
        $submittedDoctorId = $context['doctor_id'] ?? null;

        if (! $submittedDoctorId) {
            return true;
        }

        return $user->getId() === (int) $submittedDoctorId;
    }

    private function isOwner(User $user, int $prescriptionId) : bool
    {
        $userId = $user->getId();
        if (! $userId) {
            return false;
        }

        $prescription = $this->prescriptionRepository->findById($prescriptionId);
        if ($prescription && (int) $prescription['doctor_id'] === $userId) {
            return true;
        }

        if ($prescription && isset($prescription['patient_id'])) {
            return $this->appointmentRepository->isPatientAssignedToDoctor((int) $prescription['patient_id'], $userId);
        }

        return false;
    }

    private function extractPrescriptionId(mixed $subject) : ?int
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
