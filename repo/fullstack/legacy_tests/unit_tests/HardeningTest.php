<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\BookingStatus;
use App\Enum\BookingHoldStatus;
use App\Enum\BillStatus;
use App\Enum\PaymentStatus;
use App\Enum\NotificationStatus;
use App\Enum\ReconciliationRunStatus;
use App\Enum\SessionStatus;
use App\Enum\TerminalTransferStatus;
use App\Security\RbacEnforcer;
use App\Enum\UserRole;

/**
 * Second-pass hardening tests. Validates enforcement invariants that
 * must hold for the system to pass strict QA.
 */
class HardeningTest extends TestCase
{
    // --- Notification: PENDING → READ must be blocked (must go through DELIVERED) ---

    public function test_notification_pending_to_read_blocked(): void
    {
        $this->assertFalse(
            NotificationStatus::PENDING->canTransitionTo(NotificationStatus::READ),
            'PENDING must not skip DELIVERED and go directly to READ',
        );
    }

    public function test_notification_read_is_terminal(): void
    {
        $this->assertTrue(NotificationStatus::READ->isTerminal());
    }

    // --- All terminal states must block all transitions ---

    public function test_all_booking_terminal_states_block_all_transitions(): void
    {
        $terminals = [BookingStatus::COMPLETED, BookingStatus::CANCELED, BookingStatus::NO_SHOW];
        foreach ($terminals as $terminal) {
            foreach (BookingStatus::cases() as $target) {
                $this->assertFalse($terminal->canTransitionTo($target), "{$terminal->value} must not transition to {$target->value}");
            }
        }
    }

    public function test_all_hold_terminal_states_block_all_transitions(): void
    {
        $terminals = [BookingHoldStatus::EXPIRED, BookingHoldStatus::RELEASED, BookingHoldStatus::CONVERTED];
        foreach ($terminals as $terminal) {
            foreach (BookingHoldStatus::cases() as $target) {
                $this->assertFalse($terminal->canTransitionTo($target), "{$terminal->value} must not transition to {$target->value}");
            }
        }
    }

    public function test_all_payment_terminal_states_block_all_transitions(): void
    {
        $terminals = [PaymentStatus::SUCCEEDED, PaymentStatus::FAILED, PaymentStatus::REJECTED];
        foreach ($terminals as $terminal) {
            foreach (PaymentStatus::cases() as $target) {
                $this->assertFalse($terminal->canTransitionTo($target), "{$terminal->value} must not transition to {$target->value}");
            }
        }
    }

    public function test_bill_voided_is_terminal(): void
    {
        foreach (BillStatus::cases() as $target) {
            $this->assertFalse(BillStatus::VOIDED->canTransitionTo($target), "VOIDED must not transition to {$target->value}");
        }
    }

    public function test_reconciliation_terminal_states_block(): void
    {
        foreach ([ReconciliationRunStatus::COMPLETED, ReconciliationRunStatus::FAILED] as $terminal) {
            foreach (ReconciliationRunStatus::cases() as $target) {
                $this->assertFalse($terminal->canTransitionTo($target), "{$terminal->value} must not transition to {$target->value}");
            }
        }
    }

    public function test_session_terminal_states_block(): void
    {
        foreach ([SessionStatus::EXPIRED, SessionStatus::REVOKED] as $terminal) {
            foreach (SessionStatus::cases() as $target) {
                $this->assertFalse($terminal->canTransitionTo($target), "{$terminal->value} must not transition to {$target->value}");
            }
        }
    }

    public function test_transfer_terminal_states_block(): void
    {
        foreach ([TerminalTransferStatus::COMPLETED, TerminalTransferStatus::FAILED] as $terminal) {
            foreach (TerminalTransferStatus::cases() as $target) {
                $this->assertFalse($terminal->canTransitionTo($target), "{$terminal->value} must not transition to {$target->value}");
            }
        }
    }

    // --- RBAC: No role should have permissions not in the defined set ---

    public function test_tenant_has_only_view_own(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertSame([RbacEnforcer::ACTION_VIEW_OWN], $perms);
    }

    public function test_finance_clerk_cannot_manage_bookings(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::FINANCE_CLERK->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_BOOKINGS, $perms);
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_INVENTORY, $perms);
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_USERS, $perms);
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_SETTINGS, $perms);
    }

    public function test_property_manager_cannot_manage_users_or_backups(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::PROPERTY_MANAGER->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_USERS, $perms);
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_BACKUPS, $perms);
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_SETTINGS, $perms);
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_AUDIT, $perms);
    }

    // --- Financial guard: Bill outstanding amount cannot go negative ---

    public function test_bill_outstanding_amount_rejects_negative(): void
    {
        $this->expectException(\DomainException::class);
        $org = $this->createMock(\App\Entity\Organization::class);
        $org->method('getId')->willReturn('org-1');
        $user = $this->createMock(\App\Entity\User::class);

        $bill = new \App\Entity\Bill(
            'bill-1', $org, null, $user, \App\Enum\BillType::INITIAL, 'USD', '100.00',
        );
        $bill->setOutstandingAmount('-1.00');
    }

    // --- Booking: confirmed cannot go directly to completed ---

    public function test_booking_confirmed_cannot_skip_to_completed(): void
    {
        $this->assertFalse(BookingStatus::CONFIRMED->canTransitionTo(BookingStatus::COMPLETED));
    }

    // --- Hold: only ACTIVE can transition ---

    public function test_only_active_hold_has_transitions(): void
    {
        $this->assertNotEmpty(BookingHoldStatus::allowedTransitions()[BookingHoldStatus::ACTIVE->value]);
        $this->assertArrayNotHasKey(BookingHoldStatus::EXPIRED->value, BookingHoldStatus::allowedTransitions());
        $this->assertArrayNotHasKey(BookingHoldStatus::RELEASED->value, BookingHoldStatus::allowedTransitions());
        $this->assertArrayNotHasKey(BookingHoldStatus::CONVERTED->value, BookingHoldStatus::allowedTransitions());
    }
}
