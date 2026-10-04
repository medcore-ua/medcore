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

namespace App\Domain\LabOrder;

use Exception;

class LabImportService
{
    private LabOrderRepository $labOrderRepository;

    public function __construct(LabOrderRepository $labOrderRepository)
    {
        $this->labOrderRepository = $labOrderRepository;
    }

    /**
     * Performs structural validation on the uploaded file.
     * This is a placeholder for actual HL7/DICOM parsing and structural checks.
     *
     * @param  string      $filePath Path to the uploaded file.
     * @param  string      $mimeType Mime type of the uploaded file.
     * @return array|false Returns parsed data on success, or false on structural validation failure.
     * @throws Exception
     */
    public function validateStructural(string $filePath, string $mimeType)
    {
        // Placeholder for actual HL7/DICOM structural validation logic
        // In a real application, this would involve specific parsers for HL7/DICOM standards.
        $fileContent = file_get_contents($filePath);
        if (false === $fileContent) {
            throw new Exception("Не вдалося прочитати файл.");
        }

        // Basic check for file type based on mime type or extension
        if (
            str_contains($mimeType, 'application/hl7')
            || str_ends_with($filePath, '.hl7')
            || str_ends_with($filePath, '.txt')
        ) {
            // Assume it's HL7-like for now. Minimal structural check.
            if (empty($fileContent)) {
                throw new Exception("HL7/текстовий файл порожній.");
            }
            // Further HL7 parsing would go here, e.g., using a dedicated library.
            // For now, we return content as a simplified "parsed" structure.
            return ['type' => 'HL7', 'content' => $fileContent, 'parsed_data' => ['order_code' => 'HL7_TEST_' . uniqid()]];
        } elseif (
            str_contains($mimeType, 'application/dicom')
                || str_ends_with($filePath, '.dcm')
        ) {
            // Assume it's DICOM-like. Minimal structural check.
            if (empty($fileContent)) {
                throw new Exception("DICOM файл порожній.");
            }
            // Further DICOM parsing would go here, e.g., using a dedicated library.
            // For now, we return content as a simplified "parsed" structure.
            return ['type' => 'DICOM', 'content' => $fileContent, 'parsed_data' => ['order_code' => 'DICOM_TEST_' . uniqid()]];
        } else {
            throw new Exception("Непідтримуваний тип файлу: " . $mimeType);
        }
    }

    /**
     * Performs logical validation on the parsed data.
     * This is a placeholder for actual business logic validation.
     *
     * @param  array       $parsedData Data returned from structural validation.
     * @return array|false Returns processed data on success, or false on logical validation failure.
     * @throws Exception
     */
    public function validateLogical(array $parsedData)
    {
        // Placeholder for actual business logic validation.
        // E.g., check if patient exists, if doctor is valid, if order code format is correct.
        if (empty($parsedData['parsed_data']['order_code'])) {
            throw new Exception("Логічна валідація не пройдена: відсутній код замовлення.");
        }
        // Simulate checking for duplicate order code
        // if ($this->labOrderRepository->findByOrderCode($parsedData['parsed_data']['order_code'])) {
        //     throw new Exception("Логічна валідація не пройдена: код замовлення вже існує.");
        // }

        // Assume valid for now
        return $parsedData;
    }

    /**
     * Processes and imports the validated lab order data.
     *
     * @param  array     $validatedData
     * @return int|false Returns the ID of the new lab order, or false on failure.
     * @throws Exception
     */
    public function importLabOrder(array $validatedData)
    {
        // Placeholder for actual import logic
        $orderData = [
            'patient_id' => 1, // Placeholder
            'doctor_id' => 1, // Placeholder
            'medical_record_id' => 1, // Placeholder
            'order_code' => $validatedData['parsed_data']['order_code'],
            'results' => $validatedData['content'], // Store raw content for now
            'status' => 'ordered',
        ];

        $orderId = $this->labOrderRepository->save($orderData);
        if (! $orderId) {
            throw new Exception("Не вдалося зберегти лабораторне замовлення.");
        }
        return $orderId;
    }
}
