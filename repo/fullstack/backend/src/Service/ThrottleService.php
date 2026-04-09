<?php
declare(strict_types=1);
namespace App\Service;

use App\Exception\ThrottleLimitException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DB-backed throttle service. Records every hold attempt (including failures)
 * into hold_attempt_log and counts within a 60-second sliding window.
 *
 * The attempt is recorded BEFORE the limit check, so rejected and failed
 * requests still consume capacity. Survives process restarts and works
 * across multiple workers.
 */
class ThrottleService
{
    private int $defaultLimit = 30;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Record an attempt and enforce the rate limit.
     *
     * Call this BEFORE any validation so that invalid/rejected requests
     * still count toward the throttle window.
     *
     * @throws ThrottleLimitException when the sliding-window count >= limit
     */
    public function checkAndRecord(string $inventoryItemId, int $limit = 0): void
    {
        $effectiveLimit = $limit > 0 ? $limit : $this->defaultLimit;
        $now = new \DateTimeImmutable();

        // 1. Record this attempt unconditionally.
        $this->connection->insert('hold_attempt_log', [
            'id' => Uuid::v4()->toRfc4122(),
            'inventory_item_id' => $inventoryItemId,
            'attempted_at' => $now->format('Y-m-d H:i:s'),
        ]);

        // 2. Count all attempts in the sliding window (including the one just inserted).
        $windowStart = $now->modify('-60 seconds')->format('Y-m-d H:i:s');
        $recentAttempts = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM hold_attempt_log WHERE inventory_item_id = ? AND attempted_at >= ?',
            [$inventoryItemId, $windowStart],
        );

        if ($recentAttempts > $effectiveLimit) {
            throw new ThrottleLimitException();
        }
    }

    /**
     * Prune old attempt records outside the sliding window.
     * Call periodically (e.g. via cron) to keep the table small.
     */
    public function pruneExpiredAttempts(int $retentionSeconds = 120): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$retentionSeconds} seconds")->format('Y-m-d H:i:s');
        return (int) $this->connection->executeStatement(
            'DELETE FROM hold_attempt_log WHERE attempted_at < ?',
            [$cutoff],
        );
    }
}
