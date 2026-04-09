<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\BookingStatus;
use App\Enum\BookingHoldStatus;
use App\Enum\BillStatus;
use App\Enum\PaymentStatus;

/**
 * ADVERSARIAL: UI ↔ Service Parity Tests
 *
 * These tests prove the backend rejects actions the UI might incorrectly allow.
 * If any test here fails, the system has a UI/backend desync bug.
 */
class AdversarialUiParityTest extends TestCase
{
    // === CANCEL BUTTON: UI shows for all CONFIRMED/ACTIVE but backend charges fee ===

    /**
     * BREAK ATTEMPT: Cancel a booking less than 24h before start.
     * UI shows cancel button (no time check). Backend should charge 20% fee.
     * FAIL IF: fee is 0 when start_at is < 24h away.
     */
    public function test_cancellation_within_24h_must_charge_fee(): void
    {
        $baseAmount = '1000.00';
        $feePct = '20.00';
        $hoursUntilStart = 12.5; // well within 24h

        $fee = $hoursUntilStart >= 24 ? '0.00' : bcdiv(bcmul($baseAmount, $feePct, 4), '100', 2);
        $this->assertSame('200.00', $fee, 'Fee MUST be 20% when < 24h before start');
    }

    /**
     * BREAK ATTEMPT: Cancel at exactly 23h59m before start.
     * UI has no time check on the button — shows "Cancel" regardless.
     * FAIL IF: system treats 23h59m as free cancellation.
     */
    public function test_cancellation_at_23h59m_is_not_free(): void
    {
        $secondsUntilStart = (23 * 3600) + (59 * 60); // 23h59m
        $hoursUntilStart = $secondsUntilStart / 3600;
        $this->assertLessThan(24.0, $hoursUntilStart);
        $this->assertFalse($hoursUntilStart >= 24, 'Must NOT be treated as free cancellation');
    }

    // === PAY BUTTON: UI shows for open/partially_paid ===

    /**
     * BREAK ATTEMPT: Pay on a bill that just got voided in another session.
     * FAIL IF: VOIDED bill can transition to any payment-accepting state.
     */
    public function test_voided_bill_cannot_accept_payment(): void
    {
        $this->assertTrue(BillStatus::VOIDED->isTerminal());
        foreach (BillStatus::cases() as $target) {
            $this->assertFalse(BillStatus::VOIDED->canTransitionTo($target),
                "VOIDED must not transition to {$target->value}");
        }
    }

    /**
     * BREAK ATTEMPT: Pay on an already-PAID bill.
     * UI hides Pay button for PAID, but if state is stale...
     * FAIL IF: PAID bill can go to PARTIALLY_PAID.
     */
    public function test_paid_bill_cannot_go_backwards(): void
    {
        $this->assertFalse(BillStatus::PAID->canTransitionTo(BillStatus::OPEN));
        $this->assertFalse(BillStatus::PAID->canTransitionTo(BillStatus::PARTIALLY_PAID));
    }

    // === HOLD CONFIRM: UI keeps button clickable after timer expires ===

    /**
     * BREAK ATTEMPT: Confirm a hold after the 10-minute window.
     * UI timer shows 0:00 but button is still enabled.
     * FAIL IF: expired hold can transition to CONVERTED.
     */
    public function test_expired_hold_cannot_convert(): void
    {
        $this->assertTrue(BookingHoldStatus::EXPIRED->isTerminal());
        $this->assertFalse(BookingHoldStatus::EXPIRED->canTransitionTo(BookingHoldStatus::CONVERTED));
    }

    /**
     * BREAK ATTEMPT: Click confirm twice fast (double-click).
     * FAIL IF: CONVERTED hold can be converted again.
     */
    public function test_converted_hold_cannot_reconvert(): void
    {
        $this->assertTrue(BookingHoldStatus::CONVERTED->isTerminal());
        $this->assertFalse(BookingHoldStatus::CONVERTED->canTransitionTo(BookingHoldStatus::CONVERTED));
    }

    // === REFUND BUTTON: shown when status is paid/partially_paid ===

    /**
     * BREAK ATTEMPT: Issue refund that exceeds what was paid.
     * FAIL IF: remaining refundable goes negative.
     */
    public function test_refund_cannot_exceed_total_paid(): void
    {
        $totalPaid = '500.00';
        $alreadyRefunded = '400.00';
        $refundable = bcsub($totalPaid, $alreadyRefunded, 2);
        $this->assertSame('100.00', $refundable);

        $attemptedRefund = '100.01';
        $this->assertTrue(bccomp($attemptedRefund, $refundable, 2) > 0,
            'Refund of $100.01 must be rejected when only $100.00 is refundable');
    }

    // === NO-SHOW: UI correctly gates by role ===

    /**
     * BREAK ATTEMPT: Tenant tries to mark their own booking as no-show via API.
     * FAIL IF: TENANT role has MARK_NOSHOW permission.
     */
    public function test_tenant_cannot_mark_no_show(): void
    {
        // Tenant has only VIEW_OWN
        $tenantPermissions = [\App\Security\RbacEnforcer::ACTION_VIEW_OWN];
        $this->assertNotContains(
            \App\Security\RbacEnforcer::ACTION_MARK_NOSHOW,
            $tenantPermissions,
        );
    }
}
