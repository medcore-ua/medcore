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

declare(strict_types=1);

namespace App\Http\Insurance;

use App\Domain\Insurance\InsuranceService;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InsuranceController extends AbstractController
{
    private InsuranceService $insuranceService;
    private Validator $validator;

    public function __construct(InsuranceService $insuranceService, Validator $validator)
    {
        $this->insuranceService = $insuranceService;
        $this->validator = $validator;
    }

    #[Route('/insurance/companies', name: 'insurance_companies_index', methods: ['GET'])]
    public function index() : Response
    {
        $this->denyAccessUnlessGranted('INSURANCE_MANAGE');

        $companies = $this->insuranceService->getAllInsuranceCompanies();

        return $this->render('insurance/companies/index.html.twig', [
            'companies' => $companies,
        ]);
    }

    #[Route('/insurance/companies/show', name: 'insurance_companies_show', methods: ['GET'])]
    public function show() : Response
    {
        $this->denyAccessUnlessGranted('INSURANCE_VIEW');

        $id = (int)($_GET['id'] ?? 0);
        $company = $this->insuranceService->getInsuranceCompany($id);

        if (! $company) {
            return new Response('404 Not Found: Insurance company not found.', 404);
        }

        return $this->render('insurance/companies/show.html.twig', [
            'company' => $company,
        ]);
    }

    #[Route('/insurance/companies/new', name: 'insurance_companies_new_get', methods: ['GET'])]
    public function create() : Response
    {
        $this->denyAccessUnlessGranted('INSURANCE_MANAGE');

        $response = $this->render('insurance/companies/new.html.twig', [
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/insurance/companies/new', name: 'insurance_companies_new_post', methods: ['POST'])]
    public function store() : Response
    {
        $this->denyAccessUnlessGranted('INSURANCE_MANAGE');

        $validator = $this->validator;
        $rules = [
            'name' => ['required'],
        ];

        if (! $validator->validate($_POST, $rules)) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('insurance_companies_new_get');
        }

        $this->insuranceService->addInsuranceCompany(
            $_POST['name'],
            $_POST['contact_person'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['email'] ?? null,
            $_POST['notes'] ?? null
        );

        return $this->redirectToRoute('insurance_companies_index');
    }

    #[Route('/insurance/companies/edit', name: 'insurance_companies_edit_get', methods: ['GET'])]
    public function edit() : Response
    {
        $this->denyAccessUnlessGranted('INSURANCE_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $company = $this->insuranceService->getInsuranceCompany($id);

        if (! $company) {
            return new Response("Компанію не знайдено", 404);
        }

        $response = $this->render('insurance/companies/edit.html.twig', [
            'company' => $company,
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['errors']);
        return $response;
    }

    #[Route('/insurance/companies/edit', name: 'insurance_companies_edit_post', methods: ['POST'])]
    public function update() : Response
    {
        $this->denyAccessUnlessGranted('INSURANCE_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $company = $this->insuranceService->getInsuranceCompany($id);

        if (! $company) {
            return new Response("Компанію не знайдено", 404);
        }

        $validator = $this->validator;
        $rules = [
            'name' => ['required'],
        ];

        if (! $validator->validate($_POST, $rules)) {
            $_SESSION['errors'] = $validator->getErrors();
            // Redirect back to edit form
            return $this->redirectToRoute('insurance_companies_edit_get', ['id' => $id]);
        }

        $this->insuranceService->updateInsuranceCompany(
            $id,
            $_POST['name'],
            $_POST['contact_person'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['email'] ?? null,
            $_POST['notes'] ?? null
        );

        return $this->redirectToRoute('insurance_companies_index');
    }

    #[Route('/insurance/companies/delete', name: 'insurance_companies_delete', methods: ['POST'])]
    public function delete() : Response
    {
        $this->denyAccessUnlessGranted('INSURANCE_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $this->insuranceService->deleteInsuranceCompany($id);

        return $this->redirectToRoute('insurance_companies_index');
    }

    #[Route('/insurance/claims', name: 'insurance_claims_index', methods: ['GET'])]
    public function listClaims() : Response
    {
        $this->denyAccessUnlessGranted('INSURANCE_VIEW');

        $claims = $this->insuranceService->getAllClaims();

        return $this->render('insurance/claims/index.html.twig', [
            'claims' => $claims,
        ]);
    }

    #[Route('/insurance/claims/show', name: 'insurance_claims_show', methods: ['GET'])]
    public function showClaim() : Response
    {
        $this->denyAccessUnlessGranted('INSURANCE_VIEW');

        $id = (int)($_GET['id'] ?? 0);
        $claim = $this->insuranceService->getClaimWithDetails($id);

        if (! $claim) {
            return new Response("Кейс не знайдено", 404);
        }

        return $this->render('insurance/claims/show.html.twig', [
            'claim' => $claim,
        ]);
    }

    #[Route('/insurance/claims/update-status', name: 'insurance_claims_update_status', methods: ['POST'])]
    public function updateClaimStatus() : Response
    {
        $this->denyAccessUnlessGranted('INSURANCE_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'draft';
        $totalPaid = ! empty($_POST['total_paid']) ? (float)$_POST['total_paid'] : null;
        $submittedAt = ! empty($_POST['submitted_at']) ? $_POST['submitted_at'] : null;

        $this->insuranceService->updateClaimStatus($id, $status, $submittedAt, $totalPaid);

        return $this->redirectToRoute('insurance_claims_show', ['id' => $id]);
    }
}
