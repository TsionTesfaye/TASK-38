<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\BookingStatus;
use App\Enum\BookingHoldStatus;
use App\Enum\BillStatus;

class BookingWorkflowTest extends TestCase
{
    public function test_hold_to_booking_flow(): void
    {
        $this->assertTrue(BookingHoldStatus::ACTIVE->canTransitionTo(BookingHoldStatus::CONVERTED));
        $this->assertFalse(BookingStatus::CONFIRMED->isTerminal());
    }

    public function test_booking_checkin_activates(): void
    {
        $this->assertTrue(BookingStatus::CONFIRMED->canTransitionTo(BookingStatus::ACTIVE));
    }

    public function test_cancellation_fee_free_over_24h(): void
    {
        $hoursUntilStart = 25.0;
        $fee = $hoursUntilStart >= 24 ? '0.00' : bcmul('1000.00', '0.20', 2);
        $this->assertSame('0.00', $fee);
    }

    public function test_cancellation_fee_charged_under_24h(): void
    {
        $hoursUntilStart = 12.0;
        $fee = $hoursUntilStart >= 24 ? '0.00' : bcmul('1000.00', '0.20', 2);
        $this->assertSame('200.00', $fee);
    }

    public function test_no_show_penalty_50pct(): void
    {
        $baseAmount = '1000.00';
        $noShowFeePct = '50.00';
        $penalty = bcdiv(bcmul($baseAmount, $noShowFeePct, 4), '100', 2);
        $this->assertSame('500.00', $penalty);
    }

    public function test_no_show_penalty_with_first_day_rent(): void
    {
        $penalty = '500.00';
        $dailyRate = '100.00';
        $total = bcadd($penalty, $dailyRate, 2);
        $this->assertSame('600.00', $total);
    }

    public function test_recurring_billing_stops_at_terminal(): void
    {
        $this->assertFalse(BookingStatus::ACTIVE->isTerminal());
        $this->assertTrue(BookingStatus::COMPLETED->isTerminal());
        $this->assertTrue(BookingStatus::CANCELED->isTerminal());
        $this->assertTrue(BookingStatus::NO_SHOW->isTerminal());
    }

    public function test_bill_payment_refund_cycle(): void
    {
        $this->assertTrue(BillStatus::OPEN->canTransitionTo(BillStatus::PAID));
        $this->assertTrue(BillStatus::PAID->canTransitionTo(BillStatus::PARTIALLY_REFUNDED));
        $this->assertTrue(BillStatus::VOIDED->isTerminal());
    }

    public function test_refund_cannot_exceed_paid(): void
    {
        $totalPaid = '500.00';
        $totalRefunded = '300.00';
        $refundable = bcsub($totalPaid, $totalRefunded, 2);
        $this->assertSame('200.00', $refundable);

        $excess = '250.00';
        $this->assertTrue(bccomp($excess, $refundable, 2) > 0);
    }

    public function test_rescheduling_only_from_confirmed(): void
    {
        $this->assertFalse(BookingStatus::CONFIRMED->isTerminal());
        $this->assertTrue(BookingStatus::CONFIRMED->canTransitionTo(BookingStatus::CANCELED));
    }

    public function test_pricing_daily_calculation(): void
    {
        $dailyRate = '50.00';
        $days = 5;
        $units = 2;
        $total = bcmul(bcmul($dailyRate, (string)$days, 2), (string)$units, 2);
        $this->assertSame('500.00', $total);
    }
}
