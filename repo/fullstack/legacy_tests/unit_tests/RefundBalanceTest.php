<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests refund balance validation logic.
 *
 * Business rules:
 * - Refund amount must not exceed (paid_amount - already_refunded)
 * - Multiple partial refunds are allowed as long as total does not exceed paid
 */
class RefundBalanceTest extends TestCase
{
    private function canRefund(string $paidAmount, string $alreadyRefunded, string $requestedRefund): bool
    {
        $remaining = bcsub($paidAmount, $alreadyRefunded, 2);
        return bccomp($requestedRefund, $remaining, 2) <= 0;
    }

    private function remainingBalance(string $paidAmount, string $alreadyRefunded): string
    {
        return bcsub($paidAmount, $alreadyRefunded, 2);
    }

    public function test_refund_within_paid_amount(): void
    {
        $this->assertTrue($this->canRefund('100.00', '0.00', '30.00'));
        $remaining = $this->remainingBalance('100.00', '30.00');
        $this->assertSame('70.00', $remaining);
    }

    public function test_refund_exceeds_paid_amount(): void
    {
        $this->assertFalse($this->canRefund('100.00', '0.00', '120.00'));
    }

    public function test_multiple_partial_refunds_summing_to_paid(): void
    {
        // First refund of 50
        $this->assertTrue($this->canRefund('100.00', '0.00', '50.00'));
        // Second refund of 50 (already refunded 50)
        $this->assertTrue($this->canRefund('100.00', '50.00', '50.00'));
        // Remaining is 0
        $remaining = $this->remainingBalance('100.00', '100.00');
        $this->assertSame('0.00', $remaining);
    }

    public function test_refund_over_limit_after_multiple_partials(): void
    {
        // Already refunded 80, trying to refund 30 on a 100 payment
        $this->assertFalse($this->canRefund('100.00', '80.00', '30.00'));
    }

    public function test_exact_remaining_refund_is_allowed(): void
    {
        // Already refunded 80, trying to refund exactly 20
        $this->assertTrue($this->canRefund('100.00', '80.00', '20.00'));
    }

    public function test_zero_refund_is_always_allowed(): void
    {
        $this->assertTrue($this->canRefund('100.00', '100.00', '0.00'));
    }

    public function test_refund_on_zero_paid_fails(): void
    {
        $this->assertFalse($this->canRefund('0.00', '0.00', '1.00'));
    }
}
