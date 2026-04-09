<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Exception\ThrottleLimitException;
use App\Service\ThrottleService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for attempt-based throttling:
 *   - every call records an attempt (insert)
 *   - spamming invalid requests triggers throttle
 *   - valid + invalid mix still throttled correctly
 */
class ThrottleServiceTest extends TestCase
{
    private Connection&MockObject $conn;
    private ThrottleService $service;
    private int $insertCount;
    private int $currentCount;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);
        $this->service = new ThrottleService($this->conn);
        $this->insertCount = 0;
        $this->currentCount = 0;
    }

    private function configureConnectionForAttempts(int $existingAttempts): void
    {
        $this->currentCount = $existingAttempts;

        $this->conn->method('insert')->willReturnCallback(function () {
            $this->insertCount++;
            $this->currentCount++;
            return 1;
        });

        $this->conn->method('fetchOne')->willReturnCallback(function () {
            return (string) $this->currentCount;
        });
    }

    // ─── Every request records an attempt ─────────────────────────────

    public function testAttemptIsRecordedEvenWhenUnderLimit(): void
    {
        $this->configureConnectionForAttempts(0);

        $this->service->checkAndRecord('item-1', 10);

        $this->assertSame(1, $this->insertCount, 'Attempt must be inserted');
    }

    public function testAttemptIsRecordedWhenLimitExceeded(): void
    {
        $this->configureConnectionForAttempts(10); // already at limit

        try {
            $this->service->checkAndRecord('item-1', 10);
        } catch (ThrottleLimitException) {
            // expected
        }

        $this->assertSame(1, $this->insertCount, 'Attempt must still be inserted even on rejection');
    }

    // ─── Spam invalid requests → throttle triggers ────────────────────

    public function testSpammingRequestsTriggersThrottle(): void
    {
        // Simulate: each call inserts one attempt, count grows.
        $this->configureConnectionForAttempts(0);
        $limit = 3;
        $throttled = false;

        for ($i = 0; $i < 10; $i++) {
            try {
                $this->service->checkAndRecord('item-1', $limit);
            } catch (ThrottleLimitException) {
                $throttled = true;
                break;
            }
        }

        $this->assertTrue($throttled, 'Throttle must fire after exceeding limit');
        // The throttle fires when count > limit (i.e., on the 4th attempt since limit=3).
        $this->assertSame(4, $this->insertCount, 'Exactly limit+1 attempts before throttle');
    }

    // ─── Valid + invalid mix still throttled correctly ─────────────────

    public function testMixedValidAndInvalidRequestsStillThrottled(): void
    {
        // Scenario: 2 "valid" attempts already recorded (simulating successful holds).
        // Then more requests come in (which would fail validation in real code).
        // Throttle should still fire based on total attempt count.
        $this->configureConnectionForAttempts(2);
        $limit = 3;
        $throttled = false;
        $attemptsBeforeThrottle = 0;

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->service->checkAndRecord('item-1', $limit);
                $attemptsBeforeThrottle++;
            } catch (ThrottleLimitException) {
                $throttled = true;
                break;
            }
        }

        $this->assertTrue($throttled, 'Throttle must fire even with pre-existing attempts');
        // 2 existing + 1 new = 3, that's within limit. 2 existing + 2 new = 4, exceeds limit.
        $this->assertSame(1, $attemptsBeforeThrottle, 'Only 1 more attempt should be allowed');
    }

    // ─── Threshold boundary ───────────────────────────────────────────

    public function testExactlyAtLimitDoesNotThrottle(): void
    {
        // limit=5, existing=4. One more brings it to 5 which is == limit, still OK.
        $this->configureConnectionForAttempts(4);

        // Should NOT throw.
        $this->service->checkAndRecord('item-1', 5);
        $this->assertSame(1, $this->insertCount);
    }

    public function testOneOverLimitThrottles(): void
    {
        // limit=5, existing=5. One more brings it to 6 which is > limit.
        $this->configureConnectionForAttempts(5);

        $this->expectException(ThrottleLimitException::class);
        $this->service->checkAndRecord('item-1', 5);
    }

    // ─── Different items are independent ──────────────────────────────

    public function testThrottleIsPerItem(): void
    {
        // Item-1 is at the limit, item-2 has zero attempts.
        $callsByItem = ['item-1' => 5, 'item-2' => 0];

        $this->conn->method('insert')->willReturn(1);
        $this->conn->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params) use (&$callsByItem) {
                $item = $params[0];
                $callsByItem[$item] = ($callsByItem[$item] ?? 0) + 1;
                return (string) $callsByItem[$item];
            },
        );

        // item-2 should work fine even though item-1 is saturated.
        $this->service->checkAndRecord('item-2', 5);

        // item-1 at 5 + 1 insert = 6, should throw.
        $this->expectException(ThrottleLimitException::class);
        $this->service->checkAndRecord('item-1', 5);
    }
}
