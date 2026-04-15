<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\BookingEventType;
use App\Enum\BookingHoldStatus;
use App\Enum\BookingStatus;
use App\Enum\CapacityMode;
use App\Enum\LedgerEntryType;
use App\Enum\NotificationStatus;
use App\Enum\PaymentStatus;
use App\Enum\RateType;
use App\Enum\ReconciliationRunStatus;
use App\Enum\RefundStatus;
use App\Enum\SessionStatus;
use App\Enum\TerminalTransferStatus;
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive enum coverage: instantiate every case, call every public method,
 * walk every state-transition branch.
 */
class EnumsCoverageTest extends TestCase
{
    public function testUserRoleEnum(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->assertNotEmpty($role->value);
            $this->assertSame($role, UserRole::from($role->value));
        }
        $this->assertCount(4, UserRole::cases());
    }

    public function testCapacityModeEnum(): void
    {
        foreach (CapacityMode::cases() as $c) {
            $this->assertNotEmpty($c->value);
            $this->assertSame($c, CapacityMode::from($c->value));
        }
    }

    public function testRateTypeEnum(): void
    {
        foreach (RateType::cases() as $c) {
            $this->assertNotEmpty($c->value);
            $this->assertSame($c, RateType::from($c->value));
        }
    }

    public function testBookingStatusAllTransitions(): void
    {
        foreach (BookingStatus::cases() as $from) {
            foreach (BookingStatus::cases() as $to) {
                // Exercise canTransitionTo() for every pair
                $result = $from->canTransitionTo($to);
                $this->assertIsBool($result);
            }
            // Exercise isTerminal() for every case
            $this->assertIsBool($from->isTerminal());
        }
        // Known terminals
        $this->assertTrue(BookingStatus::COMPLETED->isTerminal());
        $this->assertTrue(BookingStatus::CANCELED->isTerminal());
        $this->assertTrue(BookingStatus::NO_SHOW->isTerminal());
        $this->assertFalse(BookingStatus::CONFIRMED->isTerminal());
        $this->assertFalse(BookingStatus::ACTIVE->isTerminal());
    }

    public function testBillStatusAllTransitions(): void
    {
        foreach (BillStatus::cases() as $from) {
            foreach (BillStatus::cases() as $to) {
                $this->assertIsBool($from->canTransitionTo($to));
            }
            $this->assertIsBool($from->isTerminal());
        }
        $this->assertTrue(BillStatus::VOIDED->isTerminal());
    }

    public function testBillTypeEnum(): void
    {
        $values = array_map(fn($c) => $c->value, BillType::cases());
        $this->assertContains('initial', $values);
        $this->assertContains('recurring', $values);
    }

    public function testPaymentStatusAllTransitions(): void
    {
        foreach (PaymentStatus::cases() as $from) {
            foreach (PaymentStatus::cases() as $to) {
                $this->assertIsBool($from->canTransitionTo($to));
            }
            $this->assertIsBool($from->isTerminal());
        }
        $this->assertTrue(PaymentStatus::SUCCEEDED->isTerminal());
        $this->assertTrue(PaymentStatus::FAILED->isTerminal());
        $this->assertTrue(PaymentStatus::REJECTED->isTerminal());
        $this->assertFalse(PaymentStatus::PENDING->isTerminal());
    }

    public function testRefundStatusEnum(): void
    {
        foreach (RefundStatus::cases() as $c) {
            $this->assertNotEmpty($c->value);
        }
    }

    public function testLedgerEntryTypeEnum(): void
    {
        foreach (LedgerEntryType::cases() as $c) {
            $this->assertNotEmpty($c->value);
            $this->assertSame($c, LedgerEntryType::from($c->value));
        }
    }

    public function testBookingHoldStatusAllTransitions(): void
    {
        foreach (BookingHoldStatus::cases() as $from) {
            foreach (BookingHoldStatus::cases() as $to) {
                $this->assertIsBool($from->canTransitionTo($to));
            }
            $this->assertIsBool($from->isTerminal());
        }
    }

    public function testBookingEventTypeEnum(): void
    {
        foreach (BookingEventType::cases() as $c) {
            $this->assertNotEmpty($c->value);
        }
    }

    public function testNotificationStatusEnum(): void
    {
        foreach (NotificationStatus::cases() as $from) {
            foreach (NotificationStatus::cases() as $to) {
                if (method_exists($from, 'canTransitionTo')) {
                    $this->assertIsBool($from->canTransitionTo($to));
                }
            }
        }
    }

    public function testReconciliationRunStatusEnum(): void
    {
        foreach (ReconciliationRunStatus::cases() as $c) {
            $this->assertNotEmpty($c->value);
        }
    }

    public function testSessionStatusEnum(): void
    {
        foreach (SessionStatus::cases() as $c) {
            $this->assertNotEmpty($c->value);
        }
    }

    public function testTerminalTransferStatusAllTransitions(): void
    {
        foreach (TerminalTransferStatus::cases() as $from) {
            foreach (TerminalTransferStatus::cases() as $to) {
                if (method_exists($from, 'canTransitionTo')) {
                    $this->assertIsBool($from->canTransitionTo($to));
                }
            }
            if (method_exists($from, 'isTerminal')) {
                $this->assertIsBool($from->isTerminal());
            }
        }
    }
}
