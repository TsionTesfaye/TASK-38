<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\BillingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:generate-recurring-bills', description: 'Generate recurring bills')]
class GenerateRecurringBillsCommand extends Command
{
    public function __construct(private readonly BillingService $billingService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->billingService->generateRecurringBills();
        $output->writeln(sprintf('Generated %d recurring bills.', $count));
        return Command::SUCCESS;
    }
}
