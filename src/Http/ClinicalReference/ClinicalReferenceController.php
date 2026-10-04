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

namespace App\Http\ClinicalReference;

use App\Domain\ClinicalReference\IcdCodeRepository;
use App\Domain\ClinicalReference\InterventionCodeRepository;
use MedCore\Nk0252021Parser\Parser;
use MedCore\Nk0262021Parser\Parser as Nk026Parser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ClinicalReferenceController extends AbstractController
{
    private IcdCodeRepository $icdCodeRepository;
    private InterventionCodeRepository $interventionCodeRepository;

    public function __construct(
        IcdCodeRepository $icdCodeRepository,
        InterventionCodeRepository $interventionCodeRepository
    ) {
        $this->icdCodeRepository = $icdCodeRepository;
        $this->interventionCodeRepository = $interventionCodeRepository;
    }

    #[Route('/clinical/icd-import', name: 'clinical_icd_import_form', methods: ['GET'])]
    public function icdImportForm() : Response
    {
        $this->denyAccessUnlessGranted('CLINICAL_REFERENCE_MANAGE');

        $count = $this->icdCodeRepository->countAll();
        $response = $this->render('clinical_reference/icd_import.html.twig', [
            'count' => $count,
            'errors' => $_SESSION['errors'] ?? [],
            'success_message' => $_SESSION['success_message'] ?? null,
        ]);
        unset($_SESSION['errors'], $_SESSION['success_message']);
        return $response;
    }

    #[Route('/clinical/icd-import', name: 'clinical_icd_import_run', methods: ['POST'])]
    public function icdImportRun() : Response
    {
        $this->denyAccessUnlessGranted('CLINICAL_REFERENCE_MANAGE');

        try {
            $parser = new Parser();
            $collection = $parser->parse();
            $rows = [];
            foreach ($collection as $item) {
                $code = $item->specific_code ?: $item->code;
                $description = $item->specific_name_ua ?: $item->name_ua ?: $item->name_en ?: $item->specific_name_en;
                if (! $code || ! $description) {
                    continue;
                }
                $rows[] = [
                    'code' => $code,
                    'description' => $description,
                ];
            }

            if (empty($rows)) {
                throw new \RuntimeException('Не вдалося отримати дані класифікації.');
            }

            $inserted = $this->icdCodeRepository->replaceAll($rows);
            $_SESSION['success_message'] = sprintf('Імпортовано %d записів ICD-10 (NK-025-2021).', $inserted);
        } catch (\Throwable $e) {
            $_SESSION['errors']['import'] = $e->getMessage();
        }

        return $this->redirectToRoute('clinical_index');
    }

    #[Route('/clinical/intervention-import', name: 'clinical_intervention_import_form', methods: ['GET'])]
    public function interventionImportForm() : Response
    {
        $this->denyAccessUnlessGranted('CLINICAL_REFERENCE_MANAGE');

        $count = $this->interventionCodeRepository->countAll();
        $response = $this->render('clinical_reference/intervention_import.html.twig', [
            'count' => $count,
            'errors' => $_SESSION['errors'] ?? [],
            'success_message' => $_SESSION['success_message'] ?? null,
        ]);
        unset($_SESSION['errors'], $_SESSION['success_message']);
        return $response;
    }

    #[Route('/clinical/intervention-import', name: 'clinical_intervention_import_run', methods: ['POST'])]
    public function interventionImportRun() : Response
    {
        $this->denyAccessUnlessGranted('CLINICAL_REFERENCE_MANAGE');

        try {
            $parser = new Nk026Parser();
            $collection = $parser->parse();
            $rows = [];
            foreach ($collection as $item) {
                $code = $item->specific_code ?: $item->code;
                $description = $item->specific_name_ua ?: $item->name_ua ?: $item->name_en ?: $item->specific_name_en;
                if (! $code || ! $description) {
                    continue;
                }
                $rows[] = [
                    'code' => $code,
                    'description' => $description,
                ];
            }

            if (empty($rows)) {
                throw new \RuntimeException('Не вдалося отримати дані класифікації.');
            }

            $inserted = $this->interventionCodeRepository->replaceAll($rows);
            $_SESSION['success_message'] = sprintf('Імпортовано %d записів НК 026:2021 '
                                                    . '(Класифікатор медичних інтервенцій).', $inserted);
        } catch (\Throwable $e) {
            $_SESSION['errors']['import'] = $e->getMessage();
        }

        return $this->redirectToRoute('clinical_index');
    }

    #[Route('/clinical', name: 'clinical_index', methods: ['GET'])]
    public function clinicalIndex() : Response
    {
        $this->denyAccessUnlessGranted('CLINICAL_REFERENCE_MANAGE');

        $icdCount = $this->icdCodeRepository->countAll();
        $interventionCount = $this->interventionCodeRepository->countAll();

        return $this->render('clinical_reference/index.html.twig', [
            'icdCount' => $icdCount,
            'interventionCount' => $interventionCount,
        ]);
    }
}
