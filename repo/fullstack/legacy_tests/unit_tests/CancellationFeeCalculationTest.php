<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests cancellation fee calculation logic.
 *
 * Business rules:
 * - Cancellation 24+ hours before start: free (0%)
 * - Cancellation less than 24 hours before start: cancellation_fee_pct applied
 * - Default fee percentage is 20%
 */
class CancellationFeeCalculationTest extends TestCase
{
    private string $defaultFeePct = '20.00';

    private function calculateCancellationFee(
        string $bookingAmount,
        \DateTimeImmutable $startAt,
        \DateTimeImmutable $canceledAt,
        string $feePct,
        int $freeWindowHours = 24
    ): string {
        $hoursUntilStart = ($startAt->getTimestamp() - $canceledAt->getTimestamp()) / 3600;

        if ($hoursUntilStart >= $freeWindowHours) {
            return '0.00';
        }

        return bcmul(
            $bookingAmount,
            bcdiv($feePct, '100.00', 6),
            2
        );
    }

    public function test_cancel_25_hours_before_start_is_free(): void
    {
        $startAt = new \DateTimeImmutable('2026-05-01 10:00:00');
        $canceledAt = new \DateTimeImmutable('2026-04-30 09:00:00'); // 25 hours before

        $fee = $this->calculateCancellationFee('500.00', $startAt, $canceledAt, $this->defaultFeePct);
        $this->assertSame('0.00', $fee);
    }

    public function test_cancel_23_hours_before_start_incurs_fee(): void
    {
        $startAt = new \DateTimeImmutable('2026-05-01 10:00:00');
        $canceledAt = new \DateTimeImmutable('2026-04-30 11:00:00'); // 23 hours before

        $fee = $this->calculateCancellationFee('500.00', $startAt, $canceledAt, $this->defaultFeePct);
        // 500 * 0.20 = 100.00
        $this->assertSame('100.00', $fee);
    }

    public function test_cancel_exactly_24_hours_before_is_free(): void
    {
        $startAt = new \DateTimeImmutable('2026-05-01 10:00:00');
        $canceledAt = new \DateTimeImmutable('2026-04-30 10:00:00'); // exactly 24 hours

        $fee = $this->calculateCancellationFee('500.00', $startAt, $canceledAt, $this->defaultFeePct);
        $this->assertSame('0.00', $fee);
    }

    public function test_cancel_23_hours_59_minutes_before_incurs_fee(): void
    {
        $startAt = new \DateTimeImmutable('2026-05-01 10:00:00');
        $canceledAt = new \DateTimeImmutable('2026-04-30 10:01:00'); // 23h59m before

        $fee = $this->calculateCancellationFee('500.00', $startAt, $canceledAt, $this->defaultFeePct);
        // 500 * 0.20 = 100.00
        $this->assertSame('100.00', $fee);
    }

    public function test_fee_calculation_with_bcmath_precision(): void
    {
        $startAt = new \DateTimeImmutable('2026-05-01 10:00:00');
        $canceledAt = new \DateTimeImmutable('2026-05-01 09:00:00'); // 1 hour before

        // 333.33 * 0.20 = 66.666 -> 66.66 (truncated to 2 decimal places)
        $fee = $this->calculateCancellationFee('333.33', $startAt, $canceledAt, $this->defaultFeePct);
        $this->assertSame('66.66', $fee);
    }

    public function test_fee_with_custom_percentage(): void
    {
        $startAt = new \DateTimeImmutable('2026-05-01 10:00:00');
        $canceledAt = new \DateTimeImmutable('2026-05-01 09:00:00'); // 1 hour before

        // 1000.00 * 0.15 = 150.00
        $fee = $this->calculateCancellationFee('1000.00', $startAt, $canceledAt, '15.00');
        $this->assertSame('150.00', $fee);
    }

    public function test_fee_on_large_amount(): void
    {
        $startAt = new \DateTimeImmutable('2026-05-01 10:00:00');
        $canceledAt = new \DateTimeImmutable('2026-05-01 09:30:00'); // 30 min before

        // 12500.50 * 0.20 = 2500.10
        $fee = $this->calculateCancellationFee('12500.50', $startAt, $canceledAt, $this->defaultFeePct);
        $this->assertSame('2500.10', $fee);
    }

    public function test_cancel_after_start_time_incurs_fee(): void
    {
        $startAt = new \DateTimeImmutable('2026-05-01 10:00:00');
        $canceledAt = new \DateTimeImmutable('2026-05-01 11:00:00'); // 1 hour after

        $fee = $this->calculateCancellationFee('200.00', $startAt, $canceledAt, $this->defaultFeePct);
        // hours until start is negative, so fee applies
        $this->assertSame('40.00', $fee);
    }

    public function test_zero_amount_booking_yields_zero_fee(): void
    {
        $startAt = new \DateTimeImmutable('2026-05-01 10:00:00');
        $canceledAt = new \DateTimeImmutable('2026-05-01 09:00:00');

        $fee = $this->calculateCancellationFee('0.00', $startAt, $canceledAt, $this->defaultFeePct);
        $this->assertSame('0.00', $fee);
    }
}
