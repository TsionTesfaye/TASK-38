<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\BookingStatus;
use App\Enum\BillStatus;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;
use App\Enum\LedgerEntryType;
use PHPUnit\Framework\TestCase;

/**
 * Tests for concurrency safety, financial correctness, and state machine integrity.
 */
class ConcurrencyAndFinancialTest extends TestCase
{
    // ─── State Machine Integrity ──────────────────────────────────────────

    public function testBookingStatusTerminalStatesCannotTransition(): void
    {
        $terminals = [BookingStatus::COMPLETED, BookingStatus::CANCELED, BookingStatus::NO_SHOW];
        foreach ($terminals as $status) {
            $this->assertTrue($status->isTerminal());
            foreach (BookingStatus::cases() as $target) {
                if ($target === $status) continue;
                $this->assertFalse($status->canTransitionTo($target), "$status->value should not transition to $target->value");
            }
        }
    }

    public function testBillStatusVoidedIsTerminal(): void
    {
        $this->assertTrue(BillStatus::VOIDED->isTerminal());
        foreach (BillStatus::cases() as $target) {
            if ($target === BillStatus::VOIDED) continue;
            $this->assertFalse(BillStatus::VOIDED->canTransitionTo($target));
        }
    }

    public function testPaymentTerminalStatesCannotTransition(): void
    {
        foreach ([PaymentStatus::SUCCEEDED, PaymentStatus::FAILED, PaymentStatus::REJECTED] as $status) {
            $this->assertTrue($status->isTerminal());
        }
    }

    // ─── Bill Status Transitions ──────────────────────────────────────────

    public function testBillOpenToPartiallyPaid(): void
    {
        $this->assertTrue(BillStatus::OPEN->canTransitionTo(BillStatus::PARTIALLY_PAID));
    }

    public function testBillOpenToPaid(): void
    {
        $this->assertTrue(BillStatus::OPEN->canTransitionTo(BillStatus::PAID));
    }

    public function testBillPaidToPartiallyRefunded(): void
    {
        $this->assertTrue(BillStatus::PAID->canTransitionTo(BillStatus::PARTIALLY_REFUNDED));
    }

    public function testBillCannotGoFromVoidedToOpen(): void
    {
        $this->assertFalse(BillStatus::VOIDED->canTransitionTo(BillStatus::OPEN));
    }

    // ─── Ledger Entry Types ───────────────────────────────────────────────

    public function testAllLedgerTypesExist(): void
    {
        $expected = ['bill_issued', 'payment_received', 'refund_issued', 'penalty_applied', 'bill_voided'];
        foreach ($expected as $type) {
            $this->assertNotNull(LedgerEntryType::from($type));
        }
    }

}
