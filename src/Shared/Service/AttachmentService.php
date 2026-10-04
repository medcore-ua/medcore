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

namespace App\Shared\Service;

use App\Entity\Attachment;
use App\Entity\AttachmentVersion;
use App\Shared\Repository\AttachmentAclRepository;
use App\Shared\Repository\AttachmentRepository;
use App\Shared\Repository\AttachmentVersionRepository;
use Doctrine\Persistence\ManagerRegistry;

class AttachmentService
{
    private ManagerRegistry $registry;
    private AttachmentRepository $attachmentRepository;
    private AttachmentAclRepository $aclRepository;
    private AttachmentVersionRepository $versionRepository;
    private string $uploadDir = __DIR__ . '/../../../uploads';

    public function __construct(
        ManagerRegistry $registry,
        AttachmentRepository $attachmentRepository,
        AttachmentAclRepository $aclRepository,
        AttachmentVersionRepository $versionRepository,
        ?string $uploadDir = null
    ) {
        $this->registry = $registry;
        $this->attachmentRepository = $attachmentRepository;
        $this->aclRepository = $aclRepository;
        $this->versionRepository = $versionRepository;
        if (null !== $uploadDir) {
            $this->uploadDir = $uploadDir;
        }
        if (! is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0775, true);
        }
    }

    public function uploadAttachment(array $fileData, string $entityType, int $entityId, ?int $userId = null)
    {
        if (UPLOAD_ERR_OK !== $fileData['error']) {
            return false;
        }

        $filename = basename($fileData['name']);
        $mimeType = $fileData['type'];
        $size = $fileData['size'];
        $tempPath = $fileData['tmp_name'];

        $targetDir = $this->uploadDir . '/' . $entityType . '/' . $entityId;
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $uniqueFilename = uniqid('', true) . '_' . $filename;
        $targetPath = $targetDir . '/' . $uniqueFilename;
        $relativePath = str_replace($this->uploadDir . '/', '', $targetPath);

        if (! move_uploaded_file($tempPath, $targetPath)) {
            return false;
        }

        try {
            $now = new \DateTime();

            $attachment = (new Attachment())
                ->setEntityType($entityType)
                ->setEntityId($entityId)
                ->setFilename($filename)
                ->setFilepath($relativePath)
                ->setMimeType($mimeType)
                ->setSize($size)
                ->setCreatedBy($userId)
                ->setCreatedAt($now);

            $version = (new AttachmentVersion())
                ->setAttachment($attachment)
                ->setVersionNumber(1)
                ->setFilepath($relativePath)
                ->setFilename($filename)
                ->setSize($size)
                ->setCreatedBy($userId)
                ->setCreatedAt($now);

            $em = $this->registry->getManager();
            $em->persist($attachment);
            $em->persist($version);
            $em->flush();

            return $attachment->getId();
        } catch (\Exception $e) {
            unlink($targetPath);
            return false;
        }
    }

    public function createNewVersion(int $attachmentId, array $fileData, ?int $userId = null)
    {
        $attachment = $this->attachmentRepository->findById($attachmentId);
        if (! $attachment) {
            return false;
        }

        $filename = basename($fileData['name']);
        $mimeType = $fileData['type'];
        $size = $fileData['size'];
        $tempPath = $fileData['tmp_name'];

        $targetDir = $this->uploadDir . '/' . $attachment->getEntityType() . '/' . $attachment->getEntityId();
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $uniqueFilename = uniqid('', true) . '_' . $filename;
        $targetPath = $targetDir . '/' . $uniqueFilename;
        $relativePath = str_replace($this->uploadDir . '/', '', $targetPath);

        if (! move_uploaded_file($tempPath, $targetPath)) {
            return false;
        }

        try {
            $nextVersionNumber = $this->versionRepository->getMaxVersionNumber($attachmentId) + 1;

            $version = (new AttachmentVersion())
                ->setAttachment($attachment)
                ->setVersionNumber($nextVersionNumber)
                ->setFilepath($relativePath)
                ->setFilename($filename)
                ->setSize($size)
                ->setCreatedBy($userId)
                ->setCreatedAt(new \DateTime());

            $attachment
                ->setFilepath($relativePath)
                ->setFilename($filename)
                ->setMimeType($mimeType)
                ->setSize($size)
                ->setUpdatedAt(new \DateTime());

            $em = $this->registry->getManager();
            $em->persist($version);
            $em->flush();

            return $nextVersionNumber;
        } catch (\Exception $e) {
            unlink($targetPath);
            return false;
        }
    }

    public function getAttachmentById(int $attachmentId) : ?array
    {
        $attachment = $this->attachmentRepository->findById($attachmentId);
        return $attachment ? $this->attachmentToArray($attachment) : null;
    }

    public function getAttachmentsForEntity(string $entityType, int $entityId) : array
    {
        $attachments = $this->attachmentRepository->getAttachmentsForEntity($entityType, $entityId);
        return array_map([$this, 'attachmentToArray'], $attachments);
    }

    public function getAttachmentVersions(int $attachmentId) : array
    {
        $versions = $this->versionRepository->getVersionsForAttachment($attachmentId);
        return array_map([$this, 'versionToArray'], $versions);
    }

    public function getAttachmentVersion(int $attachmentId, int $versionNumber) : ?array
    {
        $version = $this->versionRepository->getVersion($attachmentId, $versionNumber);
        return $version ? $this->versionToArray($version) : null;
    }

    public function checkViewAccess(int $attachmentId, int $userId, int $userRoleId) : bool
    {
        return $this->aclRepository->checkViewAccess($attachmentId, $userId, $userRoleId);
    }

    public function updateAccess(int $attachmentId, ?int $userId = null, ?int $roleId = null, bool $canView = false, bool $canEdit = false) : bool
    {
        return $this->aclRepository->updateAccess($attachmentId, $userId, $roleId, $canView, $canEdit);
    }

    private function attachmentToArray(Attachment $attachment) : array
    {
        return [
            'id' => $attachment->getId(),
            'entity_type' => $attachment->getEntityType(),
            'entity_id' => $attachment->getEntityId(),
            'filename' => $attachment->getFilename(),
            'filepath' => $attachment->getFilepath(),
            'mime_type' => $attachment->getMimeType(),
            'size' => $attachment->getSize(),
            'created_by' => $attachment->getCreatedBy(),
            'created_at' => $attachment->getCreatedAt(),
            'updated_at' => $attachment->getUpdatedAt(),
        ];
    }

    private function versionToArray(AttachmentVersion $version) : array
    {
        return [
            'id' => $version->getId(),
            'attachment_id' => $version->getAttachment()?->getId(),
            'version_number' => $version->getVersionNumber(),
            'filepath' => $version->getFilepath(),
            'filename' => $version->getFilename(),
            'size' => $version->getSize(),
            'created_by' => $version->getCreatedBy(),
            'created_at' => $version->getCreatedAt(),
        ];
    }
}
