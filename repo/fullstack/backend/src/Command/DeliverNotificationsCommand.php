<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:deliver-notifications', description: 'Deliver pending notifications')]
class DeliverNotificationsCommand extends Command
{
    public function __construct(private readonly NotificationService $notificationService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->notificationService->deliverPendingNotifications();
        $output->writeln(sprintf('Delivered %d notifications.', $count));
        return Command::SUCCESS;
    }
}
