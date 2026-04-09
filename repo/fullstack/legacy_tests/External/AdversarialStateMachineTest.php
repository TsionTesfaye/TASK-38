<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\BookingStatus;
use App\Enum\BookingHoldStatus;
use App\Enum\BillStatus;
use App\Enum\PaymentStatus;
use App\Enum\NotificationStatus;
use App\Enum\TerminalTransferStatus;
use App\Enum\ReconciliationRunStatus;
use App\Enum\SessionStatus;

/**
 * ADVERSARIAL: State Machine Break Tests
 *
 * Try every illegal transition. ALL must fail.
 * If any succeeds, the state machine is broken.
 */
class AdversarialStateMachineTest extends TestCase
{
    // === BOOKING: Try every impossible transition ===

    /** @dataProvider bookingIllegalTransitions */
    public function test_booking_illegal_transition(string $from, string $to): void
    {
        $fromStatus = BookingStatus::from($from);
        $toStatus = BookingStatus::from($to);
        $this->assertFalse($fromStatus->canTransitionTo($toStatus),
            "Booking must reject {$from} → {$to}");
    }

    public static function bookingIllegalTransitions(): array
    {
        return [
            'completed→active' => ['completed', 'active'],
            'completed→confirmed' => ['completed', 'confirmed'],
            'completed→canceled' => ['completed', 'canceled'],
            'canceled→confirmed' => ['canceled', 'confirmed'],
            'canceled→active' => ['canceled', 'active'],
            'canceled→completed' => ['canceled', 'completed'],
            'no_show→active' => ['no_show', 'active'],
            'no_show→confirmed' => ['no_show', 'confirmed'],
            'confirmed→completed' => ['confirmed', 'completed'],  // must go through ACTIVE
            'active→confirmed' => ['active', 'confirmed'],  // can't go backwards
        ];
    }

    // === BILL: Try financial state reversals ===

    /** @dataProvider billIllegalTransitions */
    public function test_bill_illegal_transition(string $from, string $to): void
    {
        $fromStatus = BillStatus::from($from);
        $toStatus = BillStatus::from($to);
        $this->assertFalse($fromStatus->canTransitionTo($toStatus),
            "Bill must reject {$from} → {$to}");
    }

    public static function billIllegalTransitions(): array
    {
        return [
            'voided→open' => ['voided', 'open'],
            'voided→paid' => ['voided', 'paid'],
            'voided→partially_paid' => ['voided', 'partially_paid'],
            'paid→open' => ['paid', 'open'],
            'paid→partially_paid' => ['paid', 'partially_paid'],
            'partially_refunded→open' => ['partially_refunded', 'open'],
            'partially_refunded→paid' => ['partially_refunded', 'paid'],
        ];
    }

    // === PAYMENT: Try post-terminal transitions ===

    /** @dataProvider paymentTerminalTransitions */
    public function test_payment_terminal_is_immutable(string $terminal, string $target): void
    {
        $this->assertFalse(PaymentStatus::from($terminal)->canTransitionTo(PaymentStatus::from($target)),
            "Payment must reject {$terminal} → {$target}");
    }

    public static function paymentTerminalTransitions(): array
    {
        $cases = [];
        foreach (['succeeded', 'failed', 'rejected'] as $terminal) {
            foreach (['pending', 'succeeded', 'failed', 'rejected'] as $target) {
                $cases["{$terminal}→{$target}"] = [$terminal, $target];
            }
        }
        return $cases;
    }

    // === HOLD: Try reusing expired/released/converted holds ===

    public function test_all_terminal_holds_block_all_transitions(): void
    {
        foreach ([BookingHoldStatus::EXPIRED, BookingHoldStatus::RELEASED, BookingHoldStatus::CONVERTED] as $terminal) {
            foreach (BookingHoldStatus::cases() as $target) {
                $this->assertFalse($terminal->canTransitionTo($target),
                    "{$terminal->value} must block transition to {$target->value}");
            }
        }
    }

    // === NOTIFICATION: Skip DELIVERED and go straight to READ ===

    public function test_notification_cannot_skip_delivered(): void
    {
        $this->assertFalse(NotificationStatus::PENDING->canTransitionTo(NotificationStatus::READ),
            'PENDING → READ is illegal; must go PENDING → DELIVERED → READ');
    }

    // === SELF-TRANSITIONS: No status should transition to itself ===

    public function test_no_self_transitions_booking(): void
    {
        foreach (BookingStatus::cases() as $s) {
            $this->assertFalse($s->canTransitionTo($s), "{$s->value} → {$s->value} must be blocked");
        }
    }

    public function test_no_self_transitions_bill(): void
    {
        foreach (BillStatus::cases() as $s) {
            $this->assertFalse($s->canTransitionTo($s), "{$s->value} → {$s->value} must be blocked");
        }
    }

    public function test_no_self_transitions_payment(): void
    {
        foreach (PaymentStatus::cases() as $s) {
            $this->assertFalse($s->canTransitionTo($s), "{$s->value} → {$s->value} must be blocked");
        }
    }

    public function test_no_self_transitions_hold(): void
    {
        foreach (BookingHoldStatus::cases() as $s) {
            $this->assertFalse($s->canTransitionTo($s), "{$s->value} → {$s->value} must be blocked");
        }
    }
}
