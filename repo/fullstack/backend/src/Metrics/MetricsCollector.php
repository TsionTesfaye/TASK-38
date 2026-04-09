<?php
declare(strict_types=1);
namespace App\Metrics;

class MetricsCollector
{
    /** @var array<string, float[]> */
    private array $latencies = [];
    /** @var array<string, int> */
    private array $errorCounts = [];
    /** @var array<string, int> */
    private array $schedulerSuccess = [];
    /** @var array<string, int> */
    private array $schedulerFailure = [];
    private int $notificationQueueDepth = 0;
    private int $transferQueueDepth = 0;
    private int $failedJobCount = 0;

    public function recordLatency(string $endpoint, float $durationMs): void
    {
        $this->latencies[$endpoint][] = $durationMs;
        if (count($this->latencies[$endpoint]) > 1000) {
            $this->latencies[$endpoint] = array_slice($this->latencies[$endpoint], -500);
        }
    }

    public function recordError(string $endpoint, int $statusCode): void
    {
        $key = "{$endpoint}:{$statusCode}";
        $this->errorCounts[$key] = ($this->errorCounts[$key] ?? 0) + 1;
    }

    public function setNotificationQueueDepth(int $depth): void { $this->notificationQueueDepth = $depth; }
    public function setTransferQueueDepth(int $depth): void { $this->transferQueueDepth = $depth; }
    public function incrementFailedJobs(): void { $this->failedJobCount++; }

    public function incrementSchedulerSuccess(string $taskName): void
    {
        $this->schedulerSuccess[$taskName] = ($this->schedulerSuccess[$taskName] ?? 0) + 1;
    }

    public function incrementSchedulerFailure(string $taskName): void
    {
        $this->schedulerFailure[$taskName] = ($this->schedulerFailure[$taskName] ?? 0) + 1;
        $this->failedJobCount++;
    }

    /** @return array<string, int> */
    public function getSchedulerSuccess(): array { return $this->schedulerSuccess; }

    /** @return array<string, int> */
    public function getSchedulerFailure(): array { return $this->schedulerFailure; }

    public function getLatencyP50(): float
    {
        return $this->getPercentile(0.50);
    }

    public function getLatencyP95(): float
    {
        return $this->getPercentile(0.95);
    }

    public function getErrorRate(): float
    {
        $totalErrors = array_sum($this->errorCounts);
        $totalRequests = 0;
        foreach ($this->latencies as $values) { $totalRequests += count($values); }
        return $totalRequests > 0 ? $totalErrors / $totalRequests : 0.0;
    }

    public function getSummary(): array
    {
        return [
            'latency_p50_ms' => round($this->getLatencyP50(), 2),
            'latency_p95_ms' => round($this->getLatencyP95(), 2),
            'error_rate' => round($this->getErrorRate(), 4),
            'error_counts' => $this->errorCounts,
            'notification_queue_depth' => $this->notificationQueueDepth,
            'transfer_queue_depth' => $this->transferQueueDepth,
            'failed_job_count' => $this->failedJobCount,
            'scheduler_success' => $this->schedulerSuccess,
            'scheduler_failure' => $this->schedulerFailure,
        ];
    }

    private function getPercentile(float $p): float
    {
        $all = [];
        foreach ($this->latencies as $values) {
            $all = array_merge($all, $values);
        }
        if (empty($all)) return 0.0;
        sort($all);
        $index = (int) floor(count($all) * $p);
        return $all[min($index, count($all) - 1)];
    }
}
