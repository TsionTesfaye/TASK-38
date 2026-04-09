<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SchedulerWorkerCommand;
use App\Metrics\MetricsCollector;
use App\Repository\UserRepository;
use App\Service\BackupService;
use App\Service\BillingService;
use App\Service\BookingHoldService;
use App\Service\BookingService;
use App\Service\IdempotencyService;
use App\Service\NotificationService;
use App\Service\ReconciliationService;
use App\Service\SchedulerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class SchedulerWorkerCommandTest extends TestCase
{
    private SchedulerService $service;
    private SchedulerWorkerCommand $command;
    private MetricsCollector $metrics;

    // Service mocks
    private BookingHoldService $holdService;
    private BillingService $billingService;
    private ReconciliationService $reconciliationService;
    private NotificationService $notificationService;
    private BookingService $bookingService;
    private IdempotencyService $idempotencyService;
    private BackupService $backupService;
    private UserRepository $userRepo;

    protected function setUp(): void
    {
        $this->holdService = $this->createMock(BookingHoldService::class);
        $this->billingService = $this->createMock(BillingService::class);
        $this->reconciliationService = $this->createMock(ReconciliationService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->bookingService = $this->createMock(BookingService::class);
        $this->idempotencyService = $this->createMock(IdempotencyService::class);
        $this->backupService = $this->createMock(BackupService::class);
        $this->userRepo = $this->createMock(UserRepository::class);
        $this->userRepo->method('findDistinctOrganizationIds')->willReturn([]);
        $this->metrics = new MetricsCollector();

        $this->service = new SchedulerService(
            $this->holdService, $this->billingService, $this->reconciliationService,
            $this->notificationService, $this->bookingService, $this->idempotencyService,
            $this->backupService, $this->userRepo, $this->metrics, new NullLogger(),
        );

        $this->command = new SchedulerWorkerCommand($this->service);
    }

    private function silenceAllTasks(): void
    {
        $this->holdService->method('expireHolds')->willReturn(0);
        $this->notificationService->method('deliverPendingNotifications')->willReturn(0);
        $this->bookingService->method('evaluateNoShows')->willReturn(0);
        $this->billingService->method('generateRecurringBills')->willReturn(0);
        $this->idempotencyService->method('cleanupExpired')->willReturn(0);
        $this->reconciliationService->method('runDailyReconciliation')->willReturn(0);
    }

    private function runOnce(): string
    {
        $input = new ArrayInput(['--once' => true]);
        $input->bind($this->command->getDefinition());
        $output = new BufferedOutput();
        $this->command->run($input, $output);
        return $output->fetch();
    }

    // ─── Schedule table ────────────────────────────────────────────

    public function testScheduleContainsAllRequiredTasks(): void
    {
        $schedule = $this->service->getSchedule();
        $this->assertArrayHasKey('expire_holds', $schedule);
        $this->assertArrayHasKey('deliver_notifications', $schedule);
        $this->assertArrayHasKey('generate_recurring_bills', $schedule);
        $this->assertArrayHasKey('run_reconciliation', $schedule);
        $this->assertArrayHasKey('create_backups', $schedule);
        $this->assertArrayHasKey('evaluate_no_shows', $schedule);
        $this->assertArrayHasKey('cleanup_idempotency_keys', $schedule);
    }

    public function testScheduleIntervalsAreCorrect(): void
    {
        $schedule = $this->service->getSchedule();
        $this->assertSame(60, $schedule['expire_holds']['interval']);
        $this->assertSame(60, $schedule['deliver_notifications']['interval']);
        $this->assertLessThanOrEqual(3600, $schedule['generate_recurring_bills']['interval']);
        $this->assertSame(86400, $schedule['run_reconciliation']['interval']);
        $this->assertSame(86400, $schedule['create_backups']['interval']);
    }

    public function testEveryScheduleEntryHasCallableAndInterval(): void
    {
        foreach ($this->service->getSchedule() as $name => $entry) {
            $this->assertArrayHasKey('callable', $entry, "$name missing callable");
            $this->assertArrayHasKey('interval', $entry, "$name missing interval");
            $this->assertIsCallable($entry['callable']);
            $this->assertGreaterThan(0, $entry['interval']);
        }
    }

    // ─── --once mode via command ───────────────────────────────────

    public function testOnceModeExecutesAllTasksAndExits(): void
    {
        $this->holdService->expects($this->once())->method('expireHolds')->willReturn(0);
        $this->notificationService->expects($this->once())->method('deliverPendingNotifications')->willReturn(0);
        $this->bookingService->expects($this->once())->method('evaluateNoShows')->willReturn(0);
        $this->billingService->expects($this->once())->method('generateRecurringBills')->willReturn(0);
        $this->idempotencyService->expects($this->once())->method('cleanupExpired')->willReturn(0);
        $this->reconciliationService->expects($this->once())->method('runDailyReconciliation')->willReturn(0);

        $text = $this->runOnce();

        $this->assertStringContainsString('Single cycle complete', $text);
    }

    public function testOnceModeHandlesTaskFailureGracefully(): void
    {
        $this->holdService->method('expireHolds')->willThrowException(new \RuntimeException('DB down'));
        $this->notificationService->expects($this->once())->method('deliverPendingNotifications')->willReturn(3);
        $this->bookingService->expects($this->once())->method('evaluateNoShows')->willReturn(0);
        $this->billingService->expects($this->once())->method('generateRecurringBills')->willReturn(0);
        $this->idempotencyService->expects($this->once())->method('cleanupExpired')->willReturn(0);
        $this->reconciliationService->expects($this->once())->method('runDailyReconciliation')->willReturn(0);

        $text = $this->runOnce();

        $this->assertStringContainsString('FAILED', $text);
        $this->assertStringContainsString('deliver_notifications', $text);
    }

    public function testBackupsRunForEachOrganisation(): void
    {
        $this->silenceAllTasks();
        // Override userRepo to return orgs
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findDistinctOrganizationIds')->willReturn(['org-1', 'org-2']);
        $this->backupService->expects($this->exactly(2))->method('createSystemBackup');

        $svc = new SchedulerService(
            $this->holdService, $this->billingService, $this->reconciliationService,
            $this->notificationService, $this->bookingService, $this->idempotencyService,
            $this->backupService, $userRepo, $this->metrics, new NullLogger(),
        );
        $cmd = new SchedulerWorkerCommand($svc);

        $input = new ArrayInput(['--once' => true]);
        $input->bind($cmd->getDefinition());
        $output = new BufferedOutput();
        $cmd->run($input, $output);

        $this->assertStringContainsString('create_backups', $output->fetch());
    }

    // ─── SchedulerService directly (no command) ────────────────────

    public function testRunCycleDirectlyReturnResults(): void
    {
        $this->silenceAllTasks();
        $this->service->initLastRun();

        $results = $this->service->runCycle(force: true);

        $this->assertArrayHasKey('expire_holds', $results);
        $this->assertSame('ok', $results['expire_holds']['status']);
        $this->assertArrayHasKey('duration_ms', $results['expire_holds']);
    }

    // ─── Metrics wiring ───────────────────────────────────────────

    public function testSuccessfulTaskIncrementsSuccessMetric(): void
    {
        $this->holdService->method('expireHolds')->willReturn(5);
        $this->silenceAllTasks(); // others return 0

        $this->service->initLastRun();
        $this->service->runCycle(force: true);

        $success = $this->metrics->getSchedulerSuccess();
        $this->assertArrayHasKey('expire_holds', $success);
        $this->assertSame(1, $success['expire_holds']);
    }

    public function testFailedTaskIncrementsFailureMetric(): void
    {
        $this->holdService->method('expireHolds')->willThrowException(new \RuntimeException('fail'));
        $this->notificationService->method('deliverPendingNotifications')->willReturn(0);
        $this->bookingService->method('evaluateNoShows')->willReturn(0);
        $this->billingService->method('generateRecurringBills')->willReturn(0);
        $this->idempotencyService->method('cleanupExpired')->willReturn(0);
        $this->reconciliationService->method('runDailyReconciliation')->willReturn(0);

        $this->service->initLastRun();
        $this->service->runCycle(force: true);

        $failure = $this->metrics->getSchedulerFailure();
        $this->assertArrayHasKey('expire_holds', $failure);
        $this->assertSame(1, $failure['expire_holds']);
        $this->assertSame(1, $this->metrics->getSummary()['failed_job_count']);
    }

    public function testTaskLatencyRecordedInMetrics(): void
    {
        $this->silenceAllTasks();
        $this->service->initLastRun();
        $this->service->runCycle(force: true);

        $summary = $this->metrics->getSummary();
        $this->assertGreaterThan(0, $summary['latency_p50_ms']);
    }
}
