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

namespace App\Http\Room\Admin;

use App\Domain\Room\RoomRepository;
use App\Shared\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminRoomController extends AbstractController
{
    private RoomRepository $roomRepository;
    private Validator $validator;

    public function __construct(RoomRepository $roomRepository, Validator $validator)
    {
        $this->roomRepository = $roomRepository;
        $this->validator = $validator;
    }

    #[Route('/rooms', name: 'admin_rooms_index', methods: ['GET'])]
    public function index() : Response
    {
        $this->denyAccessUnlessGranted('ROOM_MANAGE');
        $searchTerm = $_GET['search'] ?? '';
        $rooms = $this->roomRepository->findAll();

        return $this->render('room/index.html.twig', [
            'rooms' => $rooms,
            'searchTerm' => $searchTerm,
        ]);
    }

    #[Route('/rooms/new', name: 'admin_rooms_new_get', methods: ['GET'])]
    public function create() : Response
    {
        $this->denyAccessUnlessGranted('ROOM_MANAGE');

        $response = $this->render('room/new.html.twig', [
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/rooms/new', name: 'admin_rooms_new_post', methods: ['POST'])]
    public function store() : Response
    {
        $this->denyAccessUnlessGranted('ROOM_MANAGE');

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required'],
            'type' => ['required'],
            'capacity' => ['required', 'numeric', 'min:1'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_rooms_new_get');
        }

        $data = $_POST;
        $data['is_available'] = isset($_POST['is_available']) ? 1 : 0;
        $this->roomRepository->create($data);
        $_SESSION['success_message'] = "Кімнату успішно створено.";
        return $this->redirectToRoute('admin_rooms_index');
    }

    #[Route('/rooms/show', name: 'admin_rooms_show_get', methods: ['GET'])]
    public function show() : Response
    {
        $this->denyAccessUnlessGranted('ROOM_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $room = $this->roomRepository->findById($id);

        if (! $room) {
            return new Response("Кімнату не знайдено", 404);
        }

        return $this->render('room/show.html.twig', ['room' => $room]);
    }

    #[Route('/rooms/edit', name: 'admin_rooms_edit_get', methods: ['GET'])]
    public function edit() : Response
    {
        $this->denyAccessUnlessGranted('ROOM_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $room = $this->roomRepository->findById($id);

        if (! $room) {
            return new Response("Кімнату не знайдено", 404);
        }

        $response = $this->render('room/edit.html.twig', [
            'room' => $room,
            'old' => $_SESSION['old'] ?? [],
            'errors' => $_SESSION['errors'] ?? [],
        ]);
        unset($_SESSION['old'], $_SESSION['errors']);
        return $response;
    }

    #[Route('/rooms/edit', name: 'admin_rooms_edit_post', methods: ['POST'])]
    public function update() : Response
    {
        $this->denyAccessUnlessGranted('ROOM_MANAGE');

        $id = (int)($_GET['id'] ?? 0);
        $room = $this->roomRepository->findById($id);

        if (! $room) {
            return new Response("Кімнату не знайдено", 404);
        }

        $validator = $this->validator;
        $validator->validate($_POST, [
            'name' => ['required'],
            'type' => ['required'],
            'capacity' => ['required', 'numeric', 'min:1'],
        ]);

        if ($validator->hasErrors()) {
            $_SESSION['errors'] = $validator->getErrors();
            $_SESSION['old'] = $_POST;
            return $this->redirectToRoute('admin_rooms_edit_get', ['id' => $id]);
        }

        $_POST['is_available'] = isset($_POST['is_available']) ? 1 : 0;
        $this->roomRepository->update($id, $_POST);
        $_SESSION['success_message'] = "Дані кімнати успішно оновлено.";
        return $this->redirectToRoute('admin_rooms_show_get', ['id' => $id]);
    }

    #[Route('/rooms/delete', name: 'admin_rooms_delete_post', methods: ['POST'])]
    public function delete() : Response
    {
        $this->denyAccessUnlessGranted('ROOM_MANAGE');

        $id = (int)($_POST['id'] ?? 0);
        $room = $this->roomRepository->findById($id);

        if (! $room) {
            return new Response("Кімнату не знайдено", 404);
        }

        $this->roomRepository->delete($id);
        $_SESSION['success_message'] = "Кімнату успішно видалено.";
        return $this->redirectToRoute('admin_rooms_index');
    }
}
