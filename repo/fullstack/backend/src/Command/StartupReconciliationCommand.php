<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\BookingHoldService;
use App\Service\NotificationService;
use App\Service\BillingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:startup-reconciliation', description: 'Run startup reconciliation: expire holds, deliver overdue notifications, process missed billing')]
class StartupReconciliationCommand extends Command
{
    public function __construct(
        private readonly BookingHoldService $holdService,
        private readonly NotificationService $notificationService,
        private readonly BillingService $billingService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starting startup reconciliation...');
        $failures = 0;

        try {
            $expiredHolds = $this->holdService->expireHolds();
            $output->writeln(sprintf('Expired %d stale holds.', $expiredHolds));
        } catch (\Throwable $e) {
            $output->writeln('<error>Hold expiration failed: ' . $e->getMessage() . '</error>');
            $failures++;
        }

        try {
            $deliveredNotifications = $this->notificationService->deliverPendingNotifications();
            $output->writeln(sprintf('Delivered %d overdue notifications.', $deliveredNotifications));
        } catch (\Throwable $e) {
            $output->writeln('<error>Notification delivery failed: ' . $e->getMessage() . '</error>');
            $failures++;
        }

        try {
            $generatedBills = $this->billingService->generateRecurringBills();
            $output->writeln(sprintf('Processed %d missed recurring bills.', $generatedBills));
        } catch (\Throwable $e) {
            $output->writeln('<error>Recurring billing failed: ' . $e->getMessage() . '</error>');
            $failures++;
        }

        $output->writeln(sprintf('Startup reconciliation complete. Failures: %d', $failures));
        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
