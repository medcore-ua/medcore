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

namespace App\Infrastructure\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class DoctrineFactory
{
    public static function createEntityManager() : EntityManager
    {
        $projectDir = dirname(__DIR__, 3);
        $paths = [$projectDir . '/src/Entity'];
        $isDevMode = ($_ENV['APP_DEBUG'] ?? 'true') === 'true';

        $cacheDir = $projectDir . '/var/cache/doctrine';
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $cache = clone new FilesystemAdapter('doctrine', 0, $cacheDir);

        $config = ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode, $cacheDir, $cache);

        $dbParams = [
            'driver'   => 'pdo_mysql',
            'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port'     => $_ENV['DB_PORT'] ?? 3306,
            'dbname'   => $_ENV['DB_DATABASE'] ?? 'clinic',
            'user'     => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset'  => 'utf8mb4',
        ];

        $connection = DriverManager::getConnection($dbParams, $config);

        return new EntityManager($connection, $config);
    }
}
