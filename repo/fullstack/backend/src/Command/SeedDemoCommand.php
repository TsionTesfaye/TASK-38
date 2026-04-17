<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\UserRole;
use App\Exception\BootstrapAlreadyCompletedException;
use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Service\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:seed-demo', description: 'Seed demo accounts for all roles. Safe to run multiple times.')]
class SeedDemoCommand extends Command
{
    private const ADMIN_USERNAME = 'admin';
    private const ADMIN_PASSWORD = 'password123';

    private const DEMO_USERS = [
        ['username' => 'manager', 'password' => 'password123', 'display_name' => 'Demo Manager',  'role' => 'property_manager'],
        ['username' => 'tenant',  'password' => 'password123', 'display_name' => 'Demo Tenant',   'role' => 'tenant'],
        ['username' => 'clerk',   'password' => 'password123', 'display_name' => 'Demo Clerk',    'role' => 'finance_clerk'],
    ];

    public function __construct(
        private readonly AuthService $authService,
        private readonly UserService $userService,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Step 1: Bootstrap the organisation + admin (idempotent — tolerates 409).
        try {
            $this->authService->bootstrap(
                'RentOps Demo',
                'DEMO',
                self::ADMIN_USERNAME,
                self::ADMIN_PASSWORD,
                'System Admin',
                'USD',
            );
            $output->writeln('Bootstrap: organisation + admin created.');
        } catch (BootstrapAlreadyCompletedException) {
            $output->writeln('Bootstrap: organisation already exists, continuing.');
        }

        // Step 2: Find any administrator to act as the creating principal.
        $admin = $this->userRepository->findByUsername(self::ADMIN_USERNAME);

        if ($admin === null) {
            // Another test bootstrapped with a different username — find any admin.
            $admin = $this->userRepository->findOneBy(['role' => UserRole::ADMINISTRATOR]);
        }

        if ($admin === null) {
            $output->writeln('<error>No administrator found — cannot create demo users.</error>');
            return Command::FAILURE;
        }

        // Step 3: Create the remaining role accounts (idempotent — skip existing).
        $created = 0;
        $skipped = 0;
        foreach (self::DEMO_USERS as $spec) {
            if ($this->userRepository->findByUsername($spec['username']) !== null) {
                $output->writeln(sprintf('  Skipped: %s (already exists)', $spec['username']));
                $skipped++;
                continue;
            }

            try {
                $this->userService->createUser(
                    $admin,
                    $spec['username'],
                    $spec['password'],
                    $spec['display_name'],
                    $spec['role'],
                );
                $output->writeln(sprintf('  Created: %s (%s)', $spec['username'], $spec['role']));
                $created++;
            } catch (\DomainException $e) {
                // Race condition on concurrent starts — treat as already exists.
                $output->writeln(sprintf('  Skipped: %s (%s)', $spec['username'], $e->getMessage()));
                $skipped++;
            }
        }

        $output->writeln(sprintf('Demo seed complete. Created: %d, Skipped: %d.', $created, $skipped));
        return Command::SUCCESS;
    }
}
