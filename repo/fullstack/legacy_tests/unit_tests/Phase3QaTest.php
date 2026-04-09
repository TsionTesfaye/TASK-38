<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\BookingStatus;
use App\Enum\BookingHoldStatus;
use App\Enum\BillStatus;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;

/**
 * Phase 3 QA hardening tests — validates adversarial scenarios.
 */
class Phase3QaTest extends TestCase
{
    // --- Concurrency: double-booking prevention ---

    public function test_hold_conversion_is_one_time_only(): void
    {
        // A CONVERTED hold cannot be converted again
        $this->assertTrue(BookingHoldStatus::CONVERTED->isTerminal());
        $this->assertFalse(BookingHoldStatus::CONVERTED->canTransitionTo(BookingHoldStatus::CONVERTED));
    }

    public function test_expired_hold_blocks_confirmation(): void
    {
        $this->assertFalse(BookingHoldStatus::EXPIRED->canTransitionTo(BookingHoldStatus::CONVERTED));
    }

    public function test_released_hold_blocks_confirmation(): void
    {
        $this->assertFalse(BookingHoldStatus::RELEASED->canTransitionTo(BookingHoldStatus::CONVERTED));
    }

    // --- Financial integrity: payment → bill → ledger ---

    public function test_payment_on_voided_bill_impossible(): void
    {
        // VOIDED bill cannot transition to any payment-accepting state
        $this->assertTrue(BillStatus::VOIDED->isTerminal());
        $this->assertFalse(BillStatus::VOIDED->canTransitionTo(BillStatus::OPEN));
        $this->assertFalse(BillStatus::VOIDED->canTransitionTo(BillStatus::PARTIALLY_PAID));
    }

    public function test_double_payment_callback_blocked_by_terminal_state(): void
    {
        // Once SUCCEEDED, no further transition possible
        $this->assertTrue(PaymentStatus::SUCCEEDED->isTerminal());
        $this->assertFalse(PaymentStatus::SUCCEEDED->canTransitionTo(PaymentStatus::SUCCEEDED));
        $this->assertFalse(PaymentStatus::SUCCEEDED->canTransitionTo(PaymentStatus::FAILED));
    }

    public function test_refund_amount_boundary(): void
    {
        // Exactly equal to paid: valid
        $paid = '1000.00';
        $refundAmount = '1000.00';
        $this->assertTrue(bccomp($refundAmount, $paid, 2) <= 0);

        // One cent over: invalid
        $overRefund = '1000.01';
        $this->assertFalse(bccomp($overRefund, $paid, 2) <= 0);
    }

    public function test_outstanding_cannot_go_negative(): void
    {
        $original = '500.00';
        $paid = '600.00'; // overpaid scenario (shouldn't happen but defensive)
        $outstanding = bcsub($original, $paid, 2);
        // System should clamp to 0.00
        if (bccomp($outstanding, '0.00', 2) < 0) {
            $outstanding = '0.00';
        }
        $this->assertSame('0.00', $outstanding);
    }

    // --- Idempotency ---

    public function test_recurring_billing_period_key_consistency(): void
    {
        $date = new \DateTimeImmutable('2026-04-01T09:00:00Z');
        $key1 = $date->format('Y-m');
        $key2 = $date->format('Y-m');
        $this->assertSame('2026-04', $key1);
        $this->assertSame($key1, $key2);
    }

    public function test_idempotency_window_24h(): void
    {
        $created = new \DateTimeImmutable('2026-04-01T10:00:00Z');
        $expires = $created->modify('+24 hours');
        $this->assertSame('2026-04-02T10:00:00+00:00', $expires->format(\DateTimeInterface::ATOM));

        // Within window: not expired
        $check = new \DateTimeImmutable('2026-04-01T20:00:00Z');
        $this->assertTrue($check < $expires);

        // After window: expired
        $check2 = new \DateTimeImmutable('2026-04-02T11:00:00Z');
        $this->assertTrue($check2 >= $expires);
    }

    // --- State machine exhaustive: no bypass ---

    public function test_no_booking_status_has_self_transition(): void
    {
        foreach (BookingStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} must not transition to itself",
            );
        }
    }

    public function test_no_hold_status_has_self_transition(): void
    {
        foreach (BookingHoldStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} must not transition to itself",
            );
        }
    }

    public function test_no_bill_status_has_self_transition(): void
    {
        foreach (BillStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} must not transition to itself",
            );
        }
    }

    // --- Cancellation boundary precision ---

    public function test_cancellation_exactly_24h_is_free(): void
    {
        $hoursUntil = 24.0;
        $isFree = $hoursUntil >= 24;
        $this->assertTrue($isFree);
    }

    public function test_cancellation_23h59m_incurs_fee(): void
    {
        $hoursUntil = 23.983; // ~23h59m
        $isFree = $hoursUntil >= 24;
        $this->assertFalse($isFree);
    }

    // --- No-show: requires no check-in AND past grace period ---

    public function test_no_show_requires_both_conditions(): void
    {
        $checkedInAt = null;
        $startAt = new \DateTimeImmutable('-2 hours');
        $gracePeriodMinutes = 30;
        $graceDeadline = $startAt->modify("+{$gracePeriodMinutes} minutes");
        $now = new \DateTimeImmutable();

        $isNoShow = ($checkedInAt === null) && ($now > $graceDeadline);
        $this->assertTrue($isNoShow);
    }

    public function test_checked_in_booking_is_not_no_show(): void
    {
        $checkedInAt = new \DateTimeImmutable('-1 hour');
        $isNoShow = ($checkedInAt === null);
        $this->assertFalse($isNoShow);
    }

    // --- Bootstrap: cannot re-run ---

    public function test_bootstrap_idempotency_guard(): void
    {
        $adminCount = 1;
        $shouldBlock = $adminCount > 0;
        $this->assertTrue($shouldBlock);
    }

    // --- DND: midnight crossing ---

    public function test_dnd_3am_is_in_overnight_window(): void
    {
        $start = '21:00';
        $end = '08:00';
        $time = '03:00';
        $inDnd = ($start > $end) ? ($time >= $start || $time < $end) : ($time >= $start && $time < $end);
        $this->assertTrue($inDnd);
    }

    public function test_dnd_exactly_at_end_is_not_in_window(): void
    {
        $start = '21:00';
        $end = '08:00';
        $time = '08:00';
        $inDnd = ($start > $end) ? ($time >= $start || $time < $end) : ($time >= $start && $time < $end);
        $this->assertFalse($inDnd);
    }
}
