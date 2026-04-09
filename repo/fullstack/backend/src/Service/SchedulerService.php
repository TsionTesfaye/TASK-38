<?php

declare(strict_types=1);

namespace App\Service;

use App\Metrics\MetricsCollector;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Owns the schedule table and cycle execution logic.
 * SchedulerWorkerCommand handles only the loop / --once flag.
 */
class SchedulerService
{
    /** @var array<string, int> Last-run timestamps keyed by task name. */
    private array $lastRun = [];

    public function __construct(
        private readonly BookingHoldService $holdService,
        private readonly BillingService $billingService,
        private readonly ReconciliationService $reconciliationService,
        private readonly NotificationService $notificationService,
        private readonly BookingService $bookingService,
        private readonly IdempotencyService $idempotencyService,
        private readonly BackupService $backupService,
        private readonly UserRepository $userRepository,
        private readonly MetricsCollector $metricsCollector,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns the schedule table: [task_name => [callable, interval_seconds]].
     *
     * @return array<string, array{callable: callable, interval: int}>
     */
    public function getSchedule(): array
    {
        return [
            'expire_holds' => [
                'callable' => fn () => $this->holdService->expireHolds(),
                'interval' => 60,
            ],
            'deliver_notifications' => [
                'callable' => fn () => $this->notificationService->deliverPendingNotifications(),
                'interval' => 60,
            ],
            'evaluate_no_shows' => [
                'callable' => fn () => $this->bookingService->evaluateNoShows(),
                'interval' => 300,
            ],
            'generate_recurring_bills' => [
                'callable' => fn () => $this->billingService->generateRecurringBills(),
                'interval' => 3600,
            ],
            'cleanup_idempotency_keys' => [
                'callable' => fn () => $this->idempotencyService->cleanupExpired(),
                'interval' => 3600,
            ],
            'run_reconciliation' => [
                'callable' => fn () => $this->reconciliationService->runDailyReconciliation(),
                'interval' => 86400,
            ],
            'create_backups' => [
                'callable' => fn () => $this->runBackups(),
                'interval' => 86400,
            ],
        ];
    }

    /**
     * Initialise last-run timestamps. Daily tasks get a grace window;
     * sub-daily tasks fire on first cycle.
     */
    public function initLastRun(): void
    {
        $now = time();
        foreach ($this->getSchedule() as $name => ['interval' => $interval]) {
            $this->lastRun[$name] = $interval >= 86400 ? $now : 0;
        }
    }

    /**
     * Execute one scheduler cycle.
     *
     * @param bool $force If true, run ALL tasks regardless of interval (--once mode).
     * @return array<string, array{status: string, result: string, duration_ms: float}> Per-task results.
     */
    public function runCycle(bool $force = false): array
    {
        $now = time();
        $results = [];

        foreach ($this->getSchedule() as $name => ['callable' => $callable, 'interval' => $interval]) {
            $elapsed = $now - ($this->lastRun[$name] ?? 0);

            if (!$force && $elapsed < $interval) {
                continue;
            }

            $startMs = microtime(true);
            try {
                $taskResult = $callable();
                $durationMs = (microtime(true) - $startMs) * 1000;

                $this->lastRun[$name] = time();
                $this->metricsCollector->recordLatency("scheduler.{$name}", $durationMs);
                $this->metricsCollector->incrementSchedulerSuccess($name);

                $resultStr = is_int($taskResult) ? (string) $taskResult : 'done';
                $this->logger->info("[scheduler] {$name} — OK (result: {$resultStr})");
                $results[$name] = ['status' => 'ok', 'result' => $resultStr, 'duration_ms' => $durationMs];
            } catch (\Throwable $e) {
                $durationMs = (microtime(true) - $startMs) * 1000;

                $this->lastRun[$name] = time();
                $this->metricsCollector->recordLatency("scheduler.{$name}", $durationMs);
                $this->metricsCollector->incrementSchedulerFailure($name);

                $this->logger->error("[scheduler] {$name} — FAILED: {$e->getMessage()}");
                $results[$name] = ['status' => 'failed', 'result' => $e->getMessage(), 'duration_ms' => $durationMs];
            }
        }

        return $results;
    }

    private function runBackups(): int
    {
        $orgIds = $this->userRepository->findDistinctOrganizationIds();
        $count = 0;

        foreach ($orgIds as $orgId) {
            try {
                $this->backupService->createSystemBackup($orgId);
                $count++;
            } catch (\Throwable) {
                // Per-org isolation
            }
        }

        return $count;
    }
}
