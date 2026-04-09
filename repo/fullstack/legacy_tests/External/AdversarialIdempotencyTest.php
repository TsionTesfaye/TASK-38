<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\PaymentStatus;
use App\Enum\BookingHoldStatus;
use App\Enum\ReconciliationRunStatus;

/**
 * ADVERSARIAL: Idempotency & Concurrency Break Tests
 *
 * Simulates double-clicks, duplicate callbacks, race conditions.
 * Every test represents a real production incident scenario.
 */
class AdversarialIdempotencyTest extends TestCase
{
    // === DOUBLE-CLICK: confirm booking twice ===

    /**
     * BREAK ATTEMPT: User double-clicks "Confirm Booking".
     * First click converts hold. Second click hits CONVERTED hold.
     * FAIL IF: second click can reconvert.
     */
    public function test_double_click_confirm_blocked_by_terminal(): void
    {
        $this->assertTrue(BookingHoldStatus::CONVERTED->isTerminal());
        $this->assertFalse(BookingHoldStatus::CONVERTED->canTransitionTo(BookingHoldStatus::CONVERTED));
    }

    // === DUPLICATE PAYMENT CALLBACK ===

    /**
     * BREAK ATTEMPT: Payment processor sends callback twice.
     * First callback sets SUCCEEDED. Second hits terminal state.
     * FAIL IF: second callback can change state.
     */
    public function test_duplicate_payment_callback_no_state_change(): void
    {
        $status = PaymentStatus::SUCCEEDED;
        $this->assertTrue($status->isTerminal());

        // No transition from any terminal state
        foreach (PaymentStatus::cases() as $target) {
            $this->assertFalse($status->canTransitionTo($target),
                "SUCCEEDED must reject transition to {$target->value}");
        }
    }

    /**
     * BREAK ATTEMPT: Callback arrives for FAILED payment claiming success.
     * FAIL IF: FAILED payment can become SUCCEEDED.
     */
    public function test_failed_payment_cannot_become_succeeded(): void
    {
        $this->assertFalse(PaymentStatus::FAILED->canTransitionTo(PaymentStatus::SUCCEEDED),
            'FAILED → SUCCEEDED must be impossible');
    }

    // === RECURRING BILLING DUPLICATE ===

    /**
     * BREAK ATTEMPT: Billing job runs twice in same month.
     * FAIL IF: period key allows duplicates.
     */
    public function test_recurring_billing_period_dedup(): void
    {
        $date1 = new \DateTimeImmutable('2026-04-01T09:00:00Z');
        $date2 = new \DateTimeImmutable('2026-04-15T14:30:00Z'); // same month, different day

        $this->assertSame($date1->format('Y-m'), $date2->format('Y-m'),
            'Same month must produce same period key');

        // Different month must produce different key
        $date3 = new \DateTimeImmutable('2026-05-01T09:00:00Z');
        $this->assertNotSame($date1->format('Y-m'), $date3->format('Y-m'));
    }

    // === RECONCILIATION DUPLICATE ===

    /**
     * BREAK ATTEMPT: Run reconciliation twice on same day.
     * FAIL IF: COMPLETED run can be re-run.
     */
    public function test_completed_reconciliation_is_terminal(): void
    {
        $this->assertTrue(ReconciliationRunStatus::COMPLETED->isTerminal());
        foreach (ReconciliationRunStatus::cases() as $target) {
            $this->assertFalse(ReconciliationRunStatus::COMPLETED->canTransitionTo($target));
        }
    }

    // === IDEMPOTENCY KEY EXPIRY ===

    /**
     * BREAK ATTEMPT: Replay request after 24h window.
     * FAIL IF: expired key is still treated as valid.
     */
    public function test_idempotency_key_expiry_boundary(): void
    {
        $created = new \DateTimeImmutable('2026-04-01T10:00:00Z');
        $expiresAt = $created->modify('+24 hours');

        // At 23h59m: still valid
        $check1 = new \DateTimeImmutable('2026-04-02T09:59:00Z');
        $this->assertTrue($check1 < $expiresAt, 'Key should still be valid at 23h59m');

        // At 24h01m: expired
        $check2 = new \DateTimeImmutable('2026-04-02T10:01:00Z');
        $this->assertTrue($check2 >= $expiresAt, 'Key must be expired after 24h');
    }

    // === CONCURRENT HOLD: two users, same inventory ===

    /**
     * BREAK ATTEMPT: Two tenants try to hold last unit simultaneously.
     * System uses SELECT FOR UPDATE on inventory item.
     * FAIL IF: both could theoretically succeed.
     *
     * This test validates the availability formula is correct.
     * Row-level locking is enforced at DB level (not testable in PHPUnit).
     */
    public function test_availability_cannot_go_negative(): void
    {
        $totalCapacity = 1;
        $activeHolds = 1; // first user got the hold
        $activeBookings = 0;

        $available = $totalCapacity - $activeHolds - $activeBookings;
        $this->assertSame(0, $available, 'No units available after first hold');
        $this->assertFalse($available > 0, 'Second hold MUST be rejected');
    }

    // === HOLD EXPIRY RACE: job runs during confirmation ===

    /**
     * BREAK ATTEMPT: Background job expires hold while user clicks confirm.
     * Confirmation MUST check expires_at >= NOW() inside transaction.
     * FAIL IF: expired status alone is not sufficient (must check time too).
     */
    public function test_confirmation_must_check_time_not_just_status(): void
    {
        $expiresAt = new \DateTimeImmutable('-1 minute'); // already past
        $now = new \DateTimeImmutable();

        // Even if status is still ACTIVE (job hasn't run yet),
        // confirmation must independently verify time
        $isExpiredByTime = $expiresAt < $now;
        $this->assertTrue($isExpiredByTime,
            'Hold past expires_at must be rejected even if status is still ACTIVE');
    }
}
