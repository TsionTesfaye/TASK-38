<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\IdempotencyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:cleanup-idempotency-keys', description: 'Cleanup expired idempotency keys')]
class CleanupIdempotencyKeysCommand extends Command
{
    public function __construct(private readonly IdempotencyService $idempotencyService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->idempotencyService->cleanupExpired();
        $output->writeln(sprintf('Cleaned up %d expired idempotency keys.', $count));
        return Command::SUCCESS;
    }
}
