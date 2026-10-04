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

namespace App\Shared\Repository;

use App\Entity\AttachmentAcl;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AttachmentAclRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttachmentAcl::class);
    }

    public function checkViewAccess(int $attachmentId, int $userId, int $userRoleId) : bool
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT COUNT(*) FROM attachment_acl WHERE attachment_id = :attachment_id AND user_id = :user_id AND can_view = TRUE";
        if ($conn->fetchOne($sql, ['attachment_id' => $attachmentId, 'user_id' => $userId]) > 0) {
            return true;
        }

        $sql = "SELECT COUNT(*) FROM attachment_acl WHERE attachment_id = :attachment_id AND role_id = :role_id AND can_view = TRUE";
        if ($conn->fetchOne($sql, ['attachment_id' => $attachmentId, 'role_id' => $userRoleId]) > 0) {
            return true;
        }

        $sql = "SELECT COUNT(*) FROM attachment_acl WHERE attachment_id = :attachment_id";
        $hasAclEntries = $conn->fetchOne($sql, ['attachment_id' => $attachmentId]) > 0;

        return ! $hasAclEntries;
    }

    public function updateAccess(int $attachmentId, ?int $userId = null, ?int $roleId = null, bool $canView = false, bool $canEdit = false) : bool
    {
        if (null === $userId && null === $roleId) {
            return false;
        }

        $conn = $this->getEntityManager()->getConnection();
        $sql = "
            INSERT INTO attachment_acl (attachment_id, user_id, role_id, can_view, can_edit)
            VALUES (:attachment_id, :user_id, :role_id, :can_view, :can_edit)
            ON DUPLICATE KEY UPDATE can_view = :can_view, can_edit = :can_edit
        ";
        return $conn->executeStatement($sql, [
            'attachment_id' => $attachmentId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'can_view' => $canView ? 1 : 0,
            'can_edit' => $canEdit ? 1 : 0,
        ]) > 0;
    }
}
