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

namespace App\Command;

use App\Domain\User\Role;
use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:install',
    description: 'Interactive setup for the Clinic Management Platform',
)]
class InstallCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Clinic Management Platform - Installation Wizard');

        $io->section('1. Database Schema Setup');

        try {
            $io->text('Creating database schema using Doctrine...');
            $schemaTool = new SchemaTool($this->entityManager);
            $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

            // Drop schema if exists and recreate (or just update)
            // Using updateSchema is safer if tables already exist, but createSchema is more robust for fresh install
            // Let's use updateSchema to be safe and create missing tables without dropping
            $schemaTool->updateSchema($metadata);

            $io->success('Database schema is successfully synchronized.');
        } catch (\Exception $e) {
            $io->error('Failed to setup schema: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->section('2. Administrator Setup');

        $io->text('Please configure the main administrator account.');

        $email = $io->ask('Administrator Email', 'admin@clinikos.pp.ua', function ($answer) {
            if (! filter_var($answer, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Please enter a valid email address.');
            }
            return $answer;
        });

        $firstName = $io->ask('First Name', 'Sergiy');
        $lastName = $io->ask('Last Name', 'Cherneha');

        $password = $io->askHidden('Administrator Password', function ($answer) {
            if (empty($answer) || strlen($answer) < 6) {
                throw new \RuntimeException('Password must be at least 6 characters long.');
            }
            return $answer;
        });

        $io->text('Setting up the administrator account...');

        // Create or fetch Admin Role
        $roleRepo = $this->entityManager->getRepository(Role::class);
        $role = $roleRepo->findOneBy(['name' => 'admin']);

        if (! $role) {
            $role = new Role();
            $role->setName('admin');
            $role->setDescription('System Administrator');
            $this->entityManager->persist($role);
        }

        // Check if user exists, otherwise create
        $userRepo = $this->entityManager->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);

        if ($user) {
            $io->warning("User with email {$email} already exists. Updating password and ensuring admin role.");
        } else {
            $user = new User();
            $user->setEmail($email);
            // Default username to email prefix if needed, or just email
            $parts = explode('@', $email);
            $user->setUsername($parts[0]);
        }

        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
        $user->setRole($role);

        // Disable MFA by default for the first admin
        $user->setMfaEnabled(false);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success("Administrator account [{$email}] has been successfully created/updated.");

        $io->section('3. Finalization');

        // Mark as installed in .env if possible (or .env.local)
        $this->markAsInstalled($io);

        $io->success('Installation complete! You can now log in to the platform.');

        return Command::SUCCESS;
    }

    private function markAsInstalled(SymfonyStyle $io) : void
    {
        $envPath = dirname(__DIR__, 2) . '/.env';
        $envLocalPath = dirname(__DIR__, 2) . '/.env.local';

        $targetPath = file_exists($envLocalPath) ? $envLocalPath : (file_exists($envPath) ? $envPath : null);

        if ($targetPath) {
            $content = file_get_contents($targetPath);
            if (! str_contains($content, 'APP_INSTALLED=true')) {
                $content = preg_replace('/^APP_INSTALLED=.*$/m', '', $content);
                $content = trim($content) . "\nAPP_INSTALLED=true\n";
                file_put_contents($targetPath, $content);
                $io->text("Updated {$targetPath} with APP_INSTALLED=true");
            }
        }
    }
}
