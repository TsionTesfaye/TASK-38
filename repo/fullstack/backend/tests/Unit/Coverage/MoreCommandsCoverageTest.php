<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Command\CreateBackupCommand;
use App\Command\StartupReconciliationCommand;
use App\Repository\UserRepository;
use App\Service\BackupService;
use App\Service\BillingService;
use App\Service\BookingHoldService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class MoreCommandsCoverageTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // StartupReconciliationCommand
    // ═══════════════════════════════════════════════════════════════

    public function testStartupReconciliationSuccess(): void
    {
        $hold = $this->createMock(BookingHoldService::class);
        $hold->method('expireHolds')->willReturn(3);
        $notif = $this->createMock(NotificationService::class);
        $notif->method('deliverPendingNotifications')->willReturn(2);
        $billing = $this->createMock(BillingService::class);
        $billing->method('generateRecurringBills')->willReturn(1);

        $cmd = new StartupReconciliationCommand($hold, $notif, $billing);
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Expired 3 stale holds', $tester->getDisplay());
        $this->assertStringContainsString('Delivered 2 overdue', $tester->getDisplay());
        $this->assertStringContainsString('Processed 1 missed', $tester->getDisplay());
    }

    public function testStartupReconciliationHandlesAllFailures(): void
    {
        $hold = $this->createMock(BookingHoldService::class);
        $hold->method('expireHolds')->willThrowException(new \RuntimeException('hold failed'));
        $notif = $this->createMock(NotificationService::class);
        $notif->method('deliverPendingNotifications')->willThrowException(new \RuntimeException('notif failed'));
        $billing = $this->createMock(BillingService::class);
        $billing->method('generateRecurringBills')->willThrowException(new \RuntimeException('bill failed'));

        $cmd = new StartupReconciliationCommand($hold, $notif, $billing);
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Hold expiration failed', $display);
        $this->assertStringContainsString('Notification delivery failed', $display);
        $this->assertStringContainsString('Recurring billing failed', $display);
        $this->assertStringContainsString('Failures: 3', $display);
    }

    // ═══════════════════════════════════════════════════════════════
    // CreateBackupCommand
    // ═══════════════════════════════════════════════════════════════

    public function testCreateBackupNoOrgs(): void
    {
        $backup = $this->createMock(BackupService::class);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findDistinctOrganizationIds')->willReturn([]);

        $cmd = new CreateBackupCommand($backup, $userRepo);
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No organisations', $tester->getDisplay());
    }

    public function testCreateBackupSuccess(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('createSystemBackup')->willReturn(['filename' => 'backup.zip']);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findDistinctOrganizationIds')->willReturn(['org-1', 'org-2']);

        $cmd = new CreateBackupCommand($backup, $userRepo);
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Backup created for org org-1', $display);
        $this->assertStringContainsString('Backup created for org org-2', $display);
    }

    public function testCreateBackupPartialFailure(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('createSystemBackup')->willReturnCallback(function ($orgId) {
            if ($orgId === 'org-bad') {
                throw new \RuntimeException('disk full');
            }
            return ['filename' => "backup-{$orgId}.zip"];
        });

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findDistinctOrganizationIds')->willReturn(['org-ok', 'org-bad']);

        $cmd = new CreateBackupCommand($backup, $userRepo);
        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Backup FAILED for org org-bad', $tester->getDisplay());
    }
}
