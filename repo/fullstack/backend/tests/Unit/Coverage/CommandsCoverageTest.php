<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Command\CleanupIdempotencyKeysCommand;
use App\Command\DeliverNotificationsCommand;
use App\Command\EvaluateNoShowsCommand;
use App\Command\ExpireHoldsCommand;
use App\Command\GenerateRecurringBillsCommand;
use App\Command\RunReconciliationCommand;
use App\Service\BillingService;
use App\Service\BookingHoldService;
use App\Service\BookingService;
use App\Service\IdempotencyService;
use App\Service\NotificationService;
use App\Service\ReconciliationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandsCoverageTest extends TestCase
{
    public function testExpireHoldsCommand(): void
    {
        $svc = $this->createMock(BookingHoldService::class);
        $svc->expects($this->once())->method('expireHolds')->willReturn(7);

        $cmd = new ExpireHoldsCommand($svc);
        $out = new BufferedOutput();
        $result = $cmd->run(new StringInput(''), $out);

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Expired 7 holds', $out->fetch());
    }

    public function testDeliverNotificationsCommand(): void
    {
        $svc = $this->createMock(NotificationService::class);
        $svc->expects($this->once())->method('deliverPendingNotifications')->willReturn(3);

        $cmd = new DeliverNotificationsCommand($svc);
        $out = new BufferedOutput();
        $this->assertSame(0, $cmd->run(new StringInput(''), $out));
        $this->assertStringContainsString('3', $out->fetch());
    }

    public function testEvaluateNoShowsCommand(): void
    {
        $svc = $this->createMock(BookingService::class);
        $svc->expects($this->once())->method('evaluateNoShows')->willReturn(2);

        $cmd = new EvaluateNoShowsCommand($svc);
        $out = new BufferedOutput();
        $this->assertSame(0, $cmd->run(new StringInput(''), $out));
        $this->assertStringContainsString('2', $out->fetch());
    }

    public function testGenerateRecurringBillsCommand(): void
    {
        $svc = $this->createMock(BillingService::class);
        $svc->expects($this->once())->method('generateRecurringBills')->willReturn(1);

        $cmd = new GenerateRecurringBillsCommand($svc);
        $out = new BufferedOutput();
        $this->assertSame(0, $cmd->run(new StringInput(''), $out));
    }

    public function testRunReconciliationCommand(): void
    {
        $svc = $this->createMock(ReconciliationService::class);
        $svc->expects($this->once())->method('runDailyReconciliation')->willReturn(0);

        $cmd = new RunReconciliationCommand($svc);
        $out = new BufferedOutput();
        $this->assertSame(0, $cmd->run(new StringInput(''), $out));
    }

    public function testCleanupIdempotencyKeysCommand(): void
    {
        $svc = $this->createMock(IdempotencyService::class);
        $svc->expects($this->once())->method('cleanupExpired')->willReturn(5);

        $cmd = new CleanupIdempotencyKeysCommand($svc);
        $out = new BufferedOutput();
        $this->assertSame(0, $cmd->run(new StringInput(''), $out));
    }
}
