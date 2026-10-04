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

namespace App\Http\Admin;

use App\Domain\Billing\ServiceCategoryRepository;
use App\Domain\Billing\ServiceRepository;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ServiceController extends AbstractController
{
    private ServiceRepository $serviceRepository;
    private ServiceCategoryRepository $serviceCategoryRepository;
    private Validator $validator;

    public function __construct(
        ServiceRepository $serviceRepository,
        ServiceCategoryRepository $serviceCategoryRepository,
        Validator $validator
    ) {
        $this->serviceRepository = $serviceRepository;
        $this->serviceCategoryRepository = $serviceCategoryRepository;
        $this->validator = $validator;
    }

    // --- Service Management ---
    #[Route('/services', name: 'admin_services', methods: ['GET'])]
    public function listServices() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $services = $this->serviceRepository->findAll();
        return $this->render('admin/services/index.html.twig', ['services' => $services]);
    }

    #[Route('/services/new', name: 'admin_services_new', methods: ['GET'])]
    public function createService() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $categories = $this->serviceCategoryRepository->findAllCategories();
        $categoryOptions = [];
        foreach ($categories as $category) {
            $categoryOptions[$category['id']] = $category['name'];
        }

        $response = $this->render('admin/services/new.html.twig', [
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
            'categories' => $categoryOptions,
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/services/new', name: 'admin_services_new_post', methods: ['POST'])]
    public function storeService() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required', 'unique:services,name'],
            'price' => ['required', 'numeric'],
            'duration_minutes' => ['required', 'numeric', 'min:1'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_services_new');
        }

        // Normalize is_active checkbox value
        $_POST['is_active'] = isset($_POST['is_active']) ? 1 : 0;

        $this->serviceRepository->save($_POST);
        $_SESSION['success_message'] = "Послугу успішно створено.";
        return $this->redirectToRoute('admin_services');
    }

    #[Route('/services/edit', name: 'admin_services_edit', methods: ['GET'])]
    public function editService() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $service = $this->serviceRepository->findById($id);

        if (! $service) {
            return new Response("Послугу не знайдено", 404);
        }

        $categories = $this->serviceCategoryRepository->findAllCategories();
        $categoryOptions = [];
        foreach ($categories as $category) {
            $categoryOptions[$category['id']] = $category['name'];
        }

        $response = $this->render('admin/services/edit.html.twig', [
            'service' => $service,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
            'categories' => $categoryOptions,
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/services/edit', name: 'admin_services_edit_post', methods: ['POST'])]
    public function updateService() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $service = $this->serviceRepository->findById($id);

        if (! $service) {
            return new Response("Послугу не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required', 'unique:services,name,' . $id],
            'price' => ['required', 'numeric'],
            'duration_minutes' => ['required', 'numeric', 'min:1'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_services_edit', ['id' => $id]);
        }

        // Normalize is_active checkbox value
        $_POST['is_active'] = isset($_POST['is_active']) ? 1 : 0;

        $this->serviceRepository->update($id, $_POST);
        $_SESSION['success_message'] = "Послугу успішно оновлено.";
        return $this->redirectToRoute('admin_services');
    }

    #[Route('/services/delete', name: 'admin_services_delete', methods: ['POST'])]
    public function deleteService() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $this->serviceRepository->delete($id);
        $_SESSION['success_message'] = "Послугу успішно видалено.";
        return $this->redirectToRoute('admin_services');
    }

    // --- Service Category Management ---
    #[Route('/service-categories', name: 'admin_service_categories', methods: ['GET'])]
    public function listServiceCategories() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $categories = $this->serviceCategoryRepository->findAllCategories();
        return $this->render('admin/service_categories/index.html.twig', ['categories' => $categories]);
    }

    #[Route('/service-categories/new', name: 'admin_service_categories_new', methods: ['GET'])]
    public function createServiceCategory() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $response = $this->render('admin/service_categories/new.html.twig', [
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/service-categories/new', name: 'admin_service_categories_new_post', methods: ['POST'])]
    public function storeServiceCategory() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required', 'unique:service_categories,name'],
            'description' => [], // Optional
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_service_categories_new');
        }

        $this->serviceCategoryRepository->save($_POST);
        $_SESSION['success_message'] = "Категорію успішно створено.";
        return $this->redirectToRoute('admin_service_categories');
    }

    #[Route('/service-categories/edit', name: 'admin_service_categories_edit', methods: ['GET'])]
    public function editServiceCategory() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $category = $this->serviceCategoryRepository->findById($id);

        if (! $category) {
            return new Response("Категорію послуг не знайдено", 404);
        }

        $response = $this->render('admin/service_categories/edit.html.twig', [
            'category' => $category,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/service-categories/edit', name: 'admin_service_categories_edit_post', methods: ['POST'])]
    public function updateServiceCategory() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $category = $this->serviceCategoryRepository->findById($id);

        if (! $category) {
            return new Response("Категорію послуг не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required', 'unique:service_categories,name,' . $id],
            'description' => [], // Optional
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_service_categories_edit', ['id' => $id]);
        }

        $this->serviceCategoryRepository->update($id, $_POST);
        $_SESSION['success_message'] = "Категорію успішно оновлено.";
        return $this->redirectToRoute('admin_service_categories');
    }

    #[Route('/service-categories/delete', name: 'admin_service_categories_delete', methods: ['POST'])]
    public function deleteServiceCategory() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        if ($this->serviceCategoryRepository->hasServices($id)) {
            $_SESSION['error_message'] = "Не можна видалити категорію, до якої прив'язані послуги.";
            return $this->redirectToRoute('admin_service_categories');
        }
        $this->serviceCategoryRepository->delete($id);
        $_SESSION['success_message'] = "Категорію послуг успішно видалено.";
        return $this->redirectToRoute('admin_service_categories');
    }
}
