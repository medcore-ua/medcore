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

namespace App\Domain\Schedule;

use App\Domain\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ScheduleVoter extends Voter
{
    public const VIEW = 'SCHEDULE_VIEW';
    public const UPDATE = 'SCHEDULE_UPDATE';
    public const MANAGE_OWN = 'SCHEDULE_MANAGE_OWN';
    public const MANAGE_ANY = 'SCHEDULE_MANAGE_ANY';

    private Security $security;

    public function __construct(Security $security)
    {
        $this->security = $security;
    }

    protected function supports(string $attribute, mixed $subject) : bool
    {
        return in_array($attribute, [
            self::VIEW,
            self::UPDATE,
            self::MANAGE_OWN,
            self::MANAGE_ANY,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token) : bool
    {
        $user = $token->getUser();

        if (! $user instanceof User) {
            return false;
        }

        // Administrators and Medical Managers can view and update all schedules
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_MEDICAL_MANAGER')) {
            return true;
        }

        $doctorId = $this->extractDoctorId($subject);

        return match ($attribute) {
            self::VIEW => $this->canView($user, $doctorId),
            self::UPDATE => $this->canUpdate($user, $doctorId),
            self::MANAGE_OWN => $this->canManageOwn($user, $doctorId),
            self::MANAGE_ANY => $this->security->isGranted('ROLE_SCHEDULE_MANAGE_ANY'),
            default => false,
        };
    }

    private function canView(User $user, ?int $doctorId) : bool
    {
        if ($this->security->isGranted('ROLE_SCHEDULE_VIEW')) {
            if (! $this->security->isGranted('ROLE_DOCTOR') || ! $doctorId) {
                return true;
            }

            return $user->getId() === $doctorId;
        }

        return false;
    }

    private function canUpdate(User $user, ?int $doctorId) : bool
    {
        if ($this->security->isGranted('ROLE_SCHEDULE_UPDATE')) {
            if (! $this->security->isGranted('ROLE_DOCTOR') || ! $doctorId) {
                return true;
            }

            return $user->getId() === $doctorId;
        }

        return false;
    }

    private function canManageOwn(User $user, ?int $doctorId) : bool
    {
        if (! $this->security->isGranted('ROLE_DOCTOR')) {
            return false;
        }

        if (! $doctorId) {
            return true;
        }

        return $user->getId() === $doctorId;
    }

    private function extractDoctorId(mixed $subject) : ?int
    {
        if (is_int($subject) || is_string($subject)) {
            return (int) $subject;
        }

        if (is_array($subject) && isset($subject['doctor_id'])) {
            return (int) $subject['doctor_id'];
        }

        if (is_object($subject) && method_exists($subject, 'getDoctorId')) {
            return (int) $subject->getDoctorId();
        }

        return null;
    }
}
