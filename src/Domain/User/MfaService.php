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

namespace App\Domain\User;

use App\Shared\Service\QrCodeGenerator;
use Doctrine\Persistence\ManagerRegistry;
use OTPHP\HOTP;
use OTPHP\TOTP;

class MfaService
{
    private ManagerRegistry $registry;
    private QrCodeGenerator $qrCodeGenerator;
    private string $issuerName;

    public function __construct(ManagerRegistry $registry, QrCodeGenerator $qrCodeGenerator, string $issuerName = 'Clinic')
    {
        $this->registry = $registry;
        $this->qrCodeGenerator = $qrCodeGenerator;
        $this->issuerName = $issuerName;
    }

    public function generateSecret() : string
    {
        $totp = TOTP::create();
        return $totp->getSecret();
    }

    public function generateHotpSecret() : string
    {
        $hotp = HOTP::create();
        return $hotp->getSecret();
    }

    public function generateQRCode(string $secret, string $userEmail) : string
    {
        $totp = TOTP::create($secret);
        $totp->setLabel($userEmail);
        $totp->setIssuer($this->issuerName);

        $otpauthUri = $totp->getProvisioningUri();
        return $this->qrCodeGenerator->generateQrCodeAsBase64($otpauthUri);
    }

    public function generateHotpQRCode(string $secret, string $userEmail, int $counter = 0) : string
    {
        $hotp = HOTP::create($secret);
        $hotp->setLabel($userEmail);
        $hotp->setIssuer($this->issuerName);
        $hotp->setCounter($counter);

        $otpauthUri = $hotp->getProvisioningUri();
        return $this->qrCodeGenerator->generateQrCodeAsBase64($otpauthUri);
    }

    public function verifyCode(string $secret, string $code) : bool
    {
        $totp = TOTP::create($secret);
        return $totp->verify($code);
    }

    public function verifyHotpCode(string $secret, string $code, int $counter, int $window = 10) : bool
    {
        $hotp = HOTP::create($secret);
        return $hotp->verify($code, $counter, $window);
    }

    public function verifyHotpCodeWithCounter(string $secret, string $code, int $currentCounter, int $lastCounter = 0, int $window = 10) : ?int
    {
        $hotp = HOTP::create($secret);

        for ($i = $currentCounter; $i < $currentCounter + $window; $i++) {
            if ($i <= $lastCounter) {
                continue;
            }

            if ($hotp->verify($code, $i, 0)) {
                return $i;
            }
        }

        return null;
    }

    public function generateBackupCodes(int $count = 10) : array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateRandomCode(8);
        }
        return $codes;
    }

    private function generateRandomCode(int $length) : string
    {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            if ($i > 0 && 0 === $i % 4) {
                $code .= '-';
            }
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $code;
    }

    public function enableMfaForUser(int $userId, string $secret, array $backupCodes, string $mfaType = 'totp') : bool
    {
        $mfaType = in_array($mfaType, ['totp', 'hotp', 'sms', 'email'], true) ? $mfaType : 'totp';

        $conn = $this->registry->getConnection();
        $sql = "
            UPDATE users
            SET mfa_enabled = 1,
                mfa_type = :mfa_type,
                mfa_secret = :secret,
                mfa_backup_codes = :backup_codes,
                mfa_verified_at = NOW(),
                mfa_pending = 0,
                updated_at = NOW()
            WHERE id = :id
        ";

        return $conn->executeStatement($sql, [
            'id' => $userId,
            'mfa_type' => $mfaType,
            'secret' => $secret,
            'backup_codes' => json_encode($backupCodes),
        ]) > 0;
    }

    public function enableHotpForUser(int $userId, string $secret, array $backupCodes, int $counter = 0) : bool
    {
        $conn = $this->registry->getConnection();
        $sql = "
            UPDATE users
            SET mfa_enabled = 1,
                mfa_type = 'hotp',
                mfa_secret = :secret,
                mfa_backup_codes = :backup_codes,
                mfa_counter = :counter,
                mfa_last_counter = :last_counter,
                mfa_verified_at = NOW(),
                mfa_pending = 0,
                updated_at = NOW()
            WHERE id = :id
        ";

        return $conn->executeStatement($sql, [
            'id' => $userId,
            'secret' => $secret,
            'backup_codes' => json_encode($backupCodes),
            'counter' => $counter,
            'last_counter' => $counter > 0 ? $counter - 1 : 0,
        ]) > 0;
    }

    public function disableMfaForUser(int $userId) : bool
    {
        $conn = $this->registry->getConnection();
        $sql = "
            UPDATE users
            SET mfa_enabled = 0,
                mfa_type = 'totp',
                mfa_secret = NULL,
                mfa_backup_codes = NULL,
                mfa_counter = 0,
                mfa_verified_at = NULL,
                mfa_pending = 0,
                updated_at = NOW()
            WHERE id = :id
        ";

        return $conn->executeStatement($sql, ['id' => $userId]) > 0;
    }

    public function verifyUserMfa(int $userId, string $code) : bool
    {
        $conn = $this->registry->getConnection();
        $sql = "SELECT mfa_secret, mfa_type, mfa_counter, mfa_last_counter, mfa_backup_codes FROM users WHERE id = :id";
        $user = $conn->fetchAssociative($sql, ['id' => $userId]);

        if (! $user || empty($user['mfa_secret'])) {
            return false;
        }

        $mfaType = $user['mfa_type'] ?? 'totp';
        $secret = $user['mfa_secret'];
        $counter = $user['mfa_counter'] ?? 0;
        $lastCounter = $user['mfa_last_counter'] ?? 0;

        if ('hotp' === $mfaType) {
            $verifiedCounter = $this->verifyHotpCodeWithCounter($secret, $code, $counter, $lastCounter);

            if (null !== $verifiedCounter) {
                $this->updateHotpCounter($userId, $verifiedCounter);
                return true;
            }
        } else {
            if ($this->verifyCode($secret, $code)) {
                return true;
            }
        }

        $backupCodes = json_decode($user['mfa_backup_codes'] ?? '[]', true);
        if (is_array($backupCodes) && in_array(strtoupper($code), $backupCodes, true)) {
            $this->removeUsedBackupCode($userId, $code);
            return true;
        }

        return false;
    }

    private function updateHotpCounter(int $userId, int $counter) : void
    {
        $conn = $this->registry->getConnection();
        $sql = "UPDATE users SET mfa_counter = :next_counter, mfa_last_counter = :last_counter WHERE id = :id";
        $conn->executeStatement($sql, ['id' => $userId, 'next_counter' => $counter + 1, 'last_counter' => $counter]);
    }

    private function removeUsedBackupCode(int $userId, string $code) : void
    {
        $conn = $this->registry->getConnection();
        $sql = "SELECT mfa_backup_codes FROM users WHERE id = :id";
        $user = $conn->fetchAssociative($sql, ['id' => $userId]);

        if (! $user) {
            return;
        }

        $backupCodes = json_decode($user['mfa_backup_codes'] ?? '[]', true);
        $backupCodes = array_filter($backupCodes, fn ($c) => strtoupper($c) !== strtoupper($code));

        $sql = "UPDATE users SET mfa_backup_codes = :codes WHERE id = :id";
        $conn->executeStatement($sql, ['id' => $userId, 'codes' => json_encode(array_values($backupCodes))]);
    }

    public function isMfaEnabled(int $userId) : bool
    {
        $conn = $this->registry->getConnection();
        $sql = "SELECT mfa_enabled FROM users WHERE id = :id";
        $user = $conn->fetchAssociative($sql, ['id' => $userId]);

        return $user && 1 == $user['mfa_enabled'];
    }

    public function isMfaPending(int $userId) : bool
    {
        $conn = $this->registry->getConnection();
        $sql = "SELECT mfa_pending FROM users WHERE id = :id";
        $user = $conn->fetchAssociative($sql, ['id' => $userId]);

        return $user && 1 == $user['mfa_pending'];
    }

    public function setMfaPending(int $userId, bool $pending) : bool
    {
        $conn = $this->registry->getConnection();
        $sql = "UPDATE users SET mfa_pending = :pending WHERE id = :id";
        return $conn->executeStatement($sql, ['id' => $userId, 'pending' => $pending ? 1 : 0]) > 0;
    }

    public function getUserMfaStatus(int $userId) : array
    {
        $conn = $this->registry->getConnection();
        $sql = "
            SELECT mfa_enabled, mfa_type, mfa_verified_at, mfa_pending
            FROM users WHERE id = :id
        ";
        $user = $conn->fetchAssociative($sql, ['id' => $userId]);

        return [
            'enabled' => (bool)($user['mfa_enabled'] ?? false),
            'type' => $user['mfa_type'] ?? null,
            'verified_at' => $user['mfa_verified_at'] ?? null,
            'pending' => (bool)($user['mfa_pending'] ?? false),
        ];
    }
}
