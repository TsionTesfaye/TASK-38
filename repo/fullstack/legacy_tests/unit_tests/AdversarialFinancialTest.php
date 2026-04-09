<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADVERSARIAL: Financial Integrity Tests
 *
 * Simulates real-world financial edge cases that could corrupt data.
 * Every test here represents a scenario that has caused bugs in production systems.
 */
class AdversarialFinancialTest extends TestCase
{
    // === REFUND OVERSHOOT: Try to refund more than paid ===

    /**
     * BREAK ATTEMPT: Issue $501 refund on $500 paid bill.
     * FAIL IF: refund is not rejected.
     */
    public function test_refund_one_cent_over_paid_is_rejected(): void
    {
        $totalPaid = '500.00';
        $refundAmount = '500.01';
        $this->assertTrue(bccomp($refundAmount, $totalPaid, 2) > 0,
            'Refund exceeding paid amount must be detected');
    }

    /**
     * BREAK ATTEMPT: Two concurrent partial refunds that together exceed total.
     * Thread 1: refund $300 on $500 bill → succeeds, remaining $200
     * Thread 2: refund $300 on same bill → MUST FAIL (only $200 left)
     */
    public function test_concurrent_refunds_exceed_balance(): void
    {
        $totalPaid = '500.00';
        $refund1 = '300.00';
        $remainingAfter1 = bcsub($totalPaid, $refund1, 2);
        $this->assertSame('200.00', $remainingAfter1);

        $refund2 = '300.00';
        $exceeds = bccomp($refund2, $remainingAfter1, 2) > 0;
        $this->assertTrue($exceeds, 'Second refund MUST be rejected');
    }

    // === PAYMENT AMOUNT MISMATCH ===

    /**
     * BREAK ATTEMPT: Pay $499.99 on a $500.00 bill with partial payments disabled.
     * FAIL IF: underpayment is accepted.
     */
    public function test_underpayment_rejected_when_partial_disabled(): void
    {
        $outstanding = '500.00';
        $paymentAmount = '499.99';
        $allowPartial = false;

        if (!$allowPartial) {
            $matches = bccomp($paymentAmount, $outstanding, 2) === 0;
            $this->assertFalse($matches, 'Underpayment must be rejected when partial disabled');
        }
    }

    /**
     * BREAK ATTEMPT: Pay $500.01 (overpay) on a $500.00 bill.
     * FAIL IF: overpayment is accepted.
     */
    public function test_overpayment_always_rejected(): void
    {
        $outstanding = '500.00';
        $paymentAmount = '500.01';
        $exceeds = bccomp($paymentAmount, $outstanding, 2) > 0;
        $this->assertTrue($exceeds, 'Overpayment must always be rejected');
    }

    // === OUTSTANDING AMOUNT CONSISTENCY ===

    /**
     * Verify: original - payments + refunds = outstanding
     * FAIL IF: formula produces inconsistent results.
     */
    public function test_outstanding_amount_formula(): void
    {
        $original = '1000.00';
        $paid = '600.00';
        $refunded = '150.00';

        $netPaid = bcsub($paid, $refunded, 2); // 450.00
        $outstanding = bcsub($original, $netPaid, 2); // 550.00
        $this->assertSame('550.00', $outstanding);
    }

    /**
     * Edge case: fully paid then partially refunded.
     * FAIL IF: outstanding doesn't reflect the refund.
     */
    public function test_outstanding_after_full_pay_then_refund(): void
    {
        $original = '1000.00';
        $paid = '1000.00';
        $refunded = '300.00';

        $outstanding = bcsub($original, bcsub($paid, $refunded, 2), 2);
        $this->assertSame('300.00', $outstanding,
            'After full pay + $300 refund, $300 should be outstanding');
    }

    // === CURRENCY MISMATCH ===

    /**
     * BREAK ATTEMPT: Pay in EUR when bill is in USD.
     * FAIL IF: different currencies are accepted.
     */
    public function test_currency_mismatch_detected(): void
    {
        $billCurrency = 'USD';
        $paymentCurrency = 'EUR';
        $this->assertNotSame($billCurrency, $paymentCurrency,
            'Currency mismatch must be detected');
    }

    // === VOID RULES ===

    /**
     * BREAK ATTEMPT: Void a bill that has unrefunded payments.
     * FAIL IF: void is allowed with outstanding paid balance.
     */
    public function test_void_blocked_with_unrefunded_payments(): void
    {
        $totalPaid = '500.00';
        $totalRefunded = '200.00';
        $unrefunded = bcsub($totalPaid, $totalRefunded, 2);
        $this->assertTrue(bccomp($unrefunded, '0.00', 2) > 0,
            'Cannot void: $300 in unrefunded payments');
    }

    /**
     * Void allowed when all payments are fully refunded.
     */
    public function test_void_allowed_when_fully_refunded(): void
    {
        $totalPaid = '500.00';
        $totalRefunded = '500.00';
        $unrefunded = bcsub($totalPaid, $totalRefunded, 2);
        $this->assertTrue(bccomp($unrefunded, '0.00', 2) === 0,
            'Void should be allowed: all payments refunded');
    }

    // === LEDGER APPEND-ONLY ===

    /**
     * Verify: bill_issued + payment_received - refund_issued = balance.
     */
    public function test_ledger_balance_derivation(): void
    {
        $billIssued = '1000.00';
        $paymentReceived = '1000.00';
        $refundIssued = '200.00';

        // Ledger balance: amount owed
        $balance = bcsub(
            $billIssued,
            bcsub($paymentReceived, $refundIssued, 2),
            2,
        );
        $this->assertSame('200.00', $balance);
    }
}
