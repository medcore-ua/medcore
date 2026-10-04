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

namespace App\Domain\MedicalRecord;

use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class MedicalRecordVoter extends Voter
{
    public const VIEW = 'MEDICAL_RECORD_VIEW';
    public const VIEW_ANY = 'MEDICAL_RECORD_VIEW_ANY';
    public const VIEW_OWN = 'MEDICAL_RECORD_VIEW_OWN';
    public const CREATE = 'MEDICAL_RECORD_CREATE';
    public const EDIT = 'MEDICAL_RECORD_EDIT';
    public const EDIT_ANY = 'MEDICAL_RECORD_EDIT_ANY';
    public const EDIT_OWN = 'MEDICAL_RECORD_EDIT_OWN';

    private MedicalRecordRepository $medicalRecordRepository;
    private Security $security;

    public function __construct(
        MedicalRecordRepository $medicalRecordRepository,
        Security $security
    ) {
        $this->medicalRecordRepository = $medicalRecordRepository;
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

        // Administrators and Medical Managers can view everything
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_MEDICAL_MANAGER')) {
            return true;
        }

        $recordId = $this->extractRecordId($subject);

        return match ($attribute) {
            self::VIEW => $this->canView($user, $recordId),
            self::VIEW_ANY => $this->security->isGranted('ROLE_MEDICAL_RECORD_VIEW_ANY'),
            self::VIEW_OWN => $this->canViewOwn($user, $recordId),
            self::CREATE => $this->security->isGranted('ROLE_MEDICAL_RECORD_CREATE'),
            self::EDIT => $this->canEdit($user, $recordId),
            self::EDIT_ANY => $this->security->isGranted('ROLE_MEDICAL_RECORD_EDIT_ANY'),
            self::EDIT_OWN => $this->canEditOwn($user, $recordId),
            default => false,
        };
    }

    private function canView(User $user, ?int $recordId) : bool
    {
        if ($this->security->isGranted('ROLE_MEDICAL_RECORD_VIEW_ANY')) {
            return true;
        }

        if ($recordId && $this->security->isGranted('ROLE_MEDICAL_RECORD_VIEW_OWN')) {
            return $this->isOwner($user, $recordId);
        }

        return false;
    }

    private function canViewOwn(User $user, ?int $recordId) : bool
    {
        if (! $recordId) {
            return false;
        }

        return $this->isOwner($user, $recordId);
    }

    private function canEdit(User $user, ?int $recordId) : bool
    {
        if ($this->security->isGranted('ROLE_MEDICAL_RECORD_EDIT_ANY')) {
            return true;
        }

        if ($recordId && $this->security->isGranted('ROLE_MEDICAL_RECORD_EDIT_OWN')) {
            return $this->isOwner($user, $recordId);
        }

        return false;
    }

    private function canEditOwn(User $user, ?int $recordId) : bool
    {
        if (! $recordId) {
            return false;
        }

        return $this->isOwner($user, $recordId);
    }

    private function isOwner(User $user, int $recordId) : bool
    {
        $userId = $user->getId();
        if (! $userId) {
            return false;
        }
        $medicalRecord = $this->medicalRecordRepository->findById($recordId);
        return $medicalRecord && (int) $medicalRecord['doctor_id'] === $userId;
    }

    private function extractRecordId(mixed $subject) : ?int
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
