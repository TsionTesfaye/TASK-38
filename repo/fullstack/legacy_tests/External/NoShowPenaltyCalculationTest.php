<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests no-show penalty calculation logic.
 *
 * Business rules:
 * - Base penalty: no_show_fee_pct (default 50%) of booking final_amount
 * - If no_show_first_day_rent_enabled: add first day rent to penalty
 * - First day rent = daily rate for the first day of the booking
 */
class NoShowPenaltyCalculationTest extends TestCase
{
    private function calculateNoShowPenalty(
        string $bookingAmount,
        string $noShowFeePct,
        bool $firstDayRentEnabled,
        string $firstDayRent = '0.00'
    ): string {
        $basePenalty = bcmul(
            $bookingAmount,
            bcdiv($noShowFeePct, '100.00', 6),
            2
        );

        if ($firstDayRentEnabled) {
            return bcadd($basePenalty, $firstDayRent, 2);
        }

        return $basePenalty;
    }

    public function test_base_penalty_is_50_percent_of_booking_amount(): void
    {
        $penalty = $this->calculateNoShowPenalty('1000.00', '50.00', false);
        $this->assertSame('500.00', $penalty);
    }

    public function test_first_day_rent_added_when_enabled(): void
    {
        $penalty = $this->calculateNoShowPenalty('1000.00', '50.00', true, '100.00');
        // 500.00 base + 100.00 first day = 600.00
        $this->assertSame('600.00', $penalty);
    }

    public function test_first_day_rent_not_added_when_disabled(): void
    {
        $penalty = $this->calculateNoShowPenalty('1000.00', '50.00', false, '100.00');
        // Only base penalty, first day rent ignored
        $this->assertSame('500.00', $penalty);
    }

    public function test_total_calculation_with_odd_amount(): void
    {
        // 333.33 * 0.50 = 166.665 -> 166.66
        $penalty = $this->calculateNoShowPenalty('333.33', '50.00', true, '75.00');
        // 166.66 + 75.00 = 241.66
        $this->assertSame('241.66', $penalty);
    }

    public function test_penalty_with_custom_percentage(): void
    {
        $penalty = $this->calculateNoShowPenalty('800.00', '30.00', false);
        // 800 * 0.30 = 240.00
        $this->assertSame('240.00', $penalty);
    }

    public function test_penalty_with_large_first_day_rent(): void
    {
        $penalty = $this->calculateNoShowPenalty('2000.00', '50.00', true, '500.00');
        // 1000.00 base + 500.00 first day = 1500.00
        $this->assertSame('1500.00', $penalty);
    }

    public function test_zero_booking_amount_yields_first_day_rent_only(): void
    {
        $penalty = $this->calculateNoShowPenalty('0.00', '50.00', true, '150.00');
        // 0.00 base + 150.00 first day = 150.00
        $this->assertSame('150.00', $penalty);
    }

    public function test_zero_booking_amount_disabled_first_day_yields_zero(): void
    {
        $penalty = $this->calculateNoShowPenalty('0.00', '50.00', false);
        $this->assertSame('0.00', $penalty);
    }

    public function test_100_percent_penalty(): void
    {
        $penalty = $this->calculateNoShowPenalty('450.00', '100.00', false);
        $this->assertSame('450.00', $penalty);
    }
}
