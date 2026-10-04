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

namespace App\Domain\News;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NewsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsArticle::class);
    }

    public function findAll() : array
    {
        $qb = $this->createQueryBuilder('n')
            ->orderBy('n.published_at', 'DESC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findById(int $id) : ?array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
    }

    public function create(array $data) : int
    {
        $article = new NewsArticle();
        $article->setTitle($data['title']);
        $article->setMeta($data['meta'] ?? null);
        $article->setContent($data['content']);

        if (! empty($data['published_at'])) {
            try {
                $article->setPublishedAt(new \DateTime($data['published_at']));
            } catch (\Exception $e) {
            }
        } else {
            $article->setPublishedAt(new \DateTime());
        }

        if (isset($data['author_id'])) {
            $article->setAuthorId((int)$data['author_id']);
        }

        $article->setIsPublished((bool)($data['is_published'] ?? true));

        $this->getEntityManager()->persist($article);
        $this->getEntityManager()->flush();

        return $article->getId();
    }

    public function update(int $id, array $data) : bool
    {
        $qb = $this->createQueryBuilder('n')
            ->update()
            ->set('n.title', ':title')
            ->set('n.meta', ':meta')
            ->set('n.content', ':content')
            ->set('n.published_at', ':published_at')
            ->set('n.author_id', ':author_id')
            ->set('n.is_published', ':is_published')
            ->set('n.updated_at', ':updated_at')
            ->where('n.id = :id')
            ->setParameter('title', $data['title'])
            ->setParameter('meta', $data['meta'])
            ->setParameter('content', $data['content'])
            ->setParameter('published_at', $data['published_at'])
            ->setParameter('author_id', $data['author_id'])
            ->setParameter('is_published', (bool)$data['is_published'])
            ->setParameter('updated_at', new \DateTime())
            ->setParameter('id', $id);

        return $qb->getQuery()->execute() > 0;
    }

    public function delete(int $id) : bool
    {
        $qb = $this->createQueryBuilder('n')
            ->delete()
            ->where('n.id = :id')
            ->setParameter('id', $id);

        return $qb->getQuery()->execute() > 0;
    }
}
