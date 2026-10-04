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

use App\Domain\Admin\DictionaryRepository;
use App\Domain\Admin\DictionaryValueRepository;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DictionaryController extends AbstractController
{
    private DictionaryRepository $dictionaryRepository;
    private DictionaryValueRepository $dictionaryValueRepository;
    private Validator $validator;

    public function __construct(
        DictionaryRepository $dictionaryRepository,
        DictionaryValueRepository $dictionaryValueRepository,
        Validator $validator
    ) {
        $this->dictionaryRepository = $dictionaryRepository;
        $this->dictionaryValueRepository = $dictionaryValueRepository;
        $this->validator = $validator;
    }

    #[Route('/dictionaries', name: 'admin_dictionaries', methods: ['GET'])]
    public function listDictionaries() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $dictionaries = $this->dictionaryRepository->findAll();
        return $this->render('admin/dictionaries/index.html.twig', ['dictionaries' => $dictionaries]);
    }

    #[Route('/dictionaries/show', name: 'admin_dictionaries_show', methods: ['GET'])]
    public function showDictionary() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $id = (int)($_GET['id'] ?? 0);
        $dictionary = $this->dictionaryRepository->findById($id);

        if (! $dictionary) {
            return new Response("Словник не знайдено", 404);
        }

        $values = $this->dictionaryValueRepository->findValuesByDictionaryId($id);
        return $this->render('admin/dictionaries/show.html.twig', [
            'dictionary' => $dictionary,
            'values' => $values,
        ]);
    }

    #[Route('/dictionaries/new', name: 'admin_dictionaries_new', methods: ['GET'])]
    public function createDictionary() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $response = $this->render('admin/dictionaries/new.html.twig', [
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/dictionaries/new', name: 'admin_dictionaries_new_post', methods: ['POST'])]
    public function storeDictionary() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required', 'unique:dictionaries,name'], // Corrected unique validation
            'description' => ['required'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_dictionaries_new');
        }

        $this->dictionaryRepository->save($_POST);
        $_SESSION['success_message'] = "Словник успішно створено.";
        return $this->redirectToRoute('admin_dictionaries');
    }

    #[Route('/dictionaries/edit', name: 'admin_dictionaries_edit', methods: ['GET'])]
    public function editDictionary() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $dictionary = $this->dictionaryRepository->findById($id);

        if (! $dictionary) {
            return new Response("Словник не знайдено", 404);
        }

        $response = $this->render('admin/dictionaries/edit.html.twig', [
            'dictionary' => $dictionary,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/dictionaries/edit', name: 'admin_dictionaries_edit_post', methods: ['POST'])]
    public function updateDictionary() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $dictionary = $this->dictionaryRepository->findById($id);

        if (! $dictionary) {
            return new Response("Словник не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required', 'unique:dictionaries,name,' . $id], // Corrected unique validation
            'description' => ['required'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_dictionaries_edit', ['id' => $id]);
        }

        $this->dictionaryRepository->update($id, $_POST);
        $_SESSION['success_message'] = "Словник успішно оновлено.";
        return $this->redirectToRoute('admin_dictionaries');
    }

    #[Route('/dictionaries/delete', name: 'admin_dictionaries_delete', methods: ['POST'])]
    public function deleteDictionary() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $this->dictionaryRepository->delete($id);
        $_SESSION['success_message'] = "Словник успішно видалено.";
        return $this->redirectToRoute('admin_dictionaries');
    }

    // --- Dictionary Value Management ---
    #[Route('/dictionaries/values/new', name: 'admin_dictionaries_values_new', methods: ['GET'])]
    public function createDictionaryValue() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');
        $dictionaryId = (int)($_GET['dictionary_id'] ?? 0);
        $response = $this->render('admin/dictionaries/values/new.html.twig', [
            'dictionary_id' => $dictionaryId,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/dictionaries/values/new', name: 'admin_dictionaries_values_new_post', methods: ['POST'])]
    public function storeDictionaryValue() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $dictionaryId = (int)($_POST['dictionary_id'] ?? 0);
        $validator = $this->validator;
        $validator->validate($_POST, [
            'dictionary_id' => ['required'],
            'value' => ['required', 'unique:dictionary_values,value,dictionary_id,' . $dictionaryId],
            'label' => ['required'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_dictionaries_values_new', ['dictionary_id' => $dictionaryId]);
        }

        $this->dictionaryValueRepository->saveValue($_POST);
        $_SESSION['success_message'] = "Значення словника успішно створено.";
        return $this->redirectToRoute('admin_dictionaries_show', ['id' => $dictionaryId]);
    }

    #[Route('/dictionaries/values/edit', name: 'admin_dictionaries_values_edit', methods: ['GET'])]
    public function editDictionaryValue() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $value = $this->dictionaryValueRepository->findValueById($id);

        if (! $value) {
            return new Response("Значення словника не знайдено", 404);
        }

        $response = $this->render('admin/dictionaries/values/edit.html.twig', [
            'value' => $value,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/dictionaries/values/edit', name: 'admin_dictionaries_values_edit_post', methods: ['POST'])]
    public function updateDictionaryValue() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $value = $this->dictionaryValueRepository->findValueById($id);
        $dictionaryId = $value['dictionary_id'];

        $validator = $this->validator;
        $validator->validate($_POST, [
            'dictionary_id' => ['required'],
            'value' => ['required', 'unique:dictionary_values,value,dictionary_id,' . $dictionaryId . ',id,' . $id],
            'label' => ['required'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_dictionaries_values_edit', ['id' => $id]);
        }

        $this->dictionaryValueRepository->updateValue($id, $_POST);
        $_SESSION['success_message'] = "Значення словника успішно оновлено.";
        return $this->redirectToRoute('admin_dictionaries_show', ['id' => $dictionaryId]);
    }

    #[Route('/dictionaries/values/delete', name: 'admin_dictionaries_values_delete', methods: ['POST'])]
    public function deleteDictionaryValue() : Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $value = $this->dictionaryValueRepository->findValueById($id);
        $dictionaryId = $value['dictionary_id'];

        $this->dictionaryValueRepository->deleteValue($id);
        $_SESSION['success_message'] = "Значення словника успішно видалено.";
        return $this->redirectToRoute('admin_dictionaries_show', ['id' => $dictionaryId]);
    }
}
