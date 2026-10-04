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

namespace App\Http\News\Site;

use App\Domain\News\NewsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicNewsController extends AbstractController
{
    private NewsRepository $newsRepository;

    public function __construct(NewsRepository $newsRepository)
    {
        $this->newsRepository = $newsRepository;
    }

    #[Route('/news', name: 'news_index', methods: ['GET'])]
    public function index() : Response
    {
        $newsArticles = $this->newsRepository->findAll();
        return $this->render('news/public/index.html.twig', [
            'newsArticles' => $newsArticles,
        ]);
    }

    #[Route('/news/{id}', name: 'news_show', methods: ['GET'])]
    public function show(int $id) : Response
    {
        $newsArticle = $this->newsRepository->findById($id);

        if (! $newsArticle || ! $newsArticle['is_published']) {
            return $this->render('errors/error.html.twig', [
                'message' => 'Новина не знайдена або не опублікована.',
                'detail' => 'Немає статті за вказаним ідентифікатором.'
            ]);
        }

        return $this->render('news/public/show.html.twig', [
            'newsArticle' => $newsArticle,
        ]);
    }
}
