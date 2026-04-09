<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\BookingHoldService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:expire-holds', description: 'Expire stale booking holds')]
class ExpireHoldsCommand extends Command
{
    public function __construct(private readonly BookingHoldService $holdService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->holdService->expireHolds();
        $output->writeln(sprintf('Expired %d holds.', $count));
        return Command::SUCCESS;
    }
}
