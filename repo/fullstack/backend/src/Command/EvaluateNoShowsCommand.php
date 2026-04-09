<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\BookingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:evaluate-no-shows', description: 'Evaluate no-shows for bookings')]
class EvaluateNoShowsCommand extends Command
{
    public function __construct(private readonly BookingService $bookingService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->bookingService->evaluateNoShows();
        $output->writeln(sprintf('Evaluated %d no-shows.', $count));
        return Command::SUCCESS;
    }
}
