<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\ReconciliationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:run-reconciliation', description: 'Run daily reconciliation')]
class RunReconciliationCommand extends Command
{
    public function __construct(private readonly ReconciliationService $reconciliationService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->reconciliationService->runDailyReconciliation();
        $output->writeln(sprintf('Reconciliation complete. %d items processed.', $result));
        return Command::SUCCESS;
    }
}
