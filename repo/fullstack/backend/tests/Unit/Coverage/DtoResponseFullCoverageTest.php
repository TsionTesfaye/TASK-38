<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\DTO\Response\AuditLogResponse;
use App\DTO\Response\BillResponse;
use App\DTO\Response\BookingHoldResponse;
use App\DTO\Response\BookingResponse;
use App\DTO\Response\InventoryItemResponse;
use App\DTO\Response\LedgerEntryResponse;
use App\DTO\Response\NotificationPreferenceResponse;
use App\DTO\Response\OrganizationResponse;
use App\DTO\Response\PlaylistResponse;
use App\DTO\Response\PricingResponse;
use App\DTO\Response\ReconciliationRunResponse;
use App\DTO\Response\RefundResponse;
use App\DTO\Response\TransferResponse;
use App\DTO\Response\UserResponse;
use App\Entity\AuditLog;
use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\BookingHold;
use App\Entity\InventoryItem;
use App\Entity\InventoryPricing;
use App\Entity\LedgerEntry;
use App\Entity\NotificationPreference;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Entity\ReconciliationRun;
use App\Entity\Refund;
use App\Entity\Terminal;
use App\Entity\TerminalPackageTransfer;
use App\Entity\TerminalPlaylist;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\BookingHoldStatus;
use App\Enum\BookingStatus;
use App\Enum\CapacityMode;
use App\Enum\LedgerEntryType;
use App\Enum\PaymentStatus;
use App\Enum\RateType;
use App\Enum\RefundStatus;
use App\Enum\TerminalTransferStatus;
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive fromEntity() + toArray() coverage for all Response DTOs using
 * real entities with optional fields populated (covers conditional format()
 * branches on nullable datetime getters).
 */
class DtoResponseFullCoverageTest extends TestCase
{
    private Organization $org;
    private User $admin;
    private User $tenant;
    private InventoryItem $item;

    protected function setUp(): void
    {
        $this->org = new Organization('org-dto', 'DTO', 'DTO Org', 'USD');
        $this->admin = new User('u-a', $this->org, 'a', 'h', 'A', UserRole::ADMINISTRATOR);
        $this->tenant = new User('u-t', $this->org, 't', 'h', 'T', UserRole::TENANT);
        $this->item = new InventoryItem('it-d', $this->org, 'A-1', 'R', 'studio', 'L', CapacityMode::DISCRETE_UNITS, 2, 'UTC');
    }

    public function testBookingResponseWithSourceHoldAndLifecycleTimestamps(): void
    {
        $hold = new BookingHold(
            'h-1', $this->org, $this->item, $this->tenant, 'req', 1,
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+2 day'),
            new \DateTimeImmutable('+10 minutes'),
        );

        $booking = new Booking(
            'bk-full', $this->org, $this->item, $this->tenant, $hold,
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 day'),
            1, 'USD', '100.00', '100.00',
        );
        // Drive through lifecycle so all timestamps are populated
        $booking->markCheckedIn();
        $booking->markCompleted();

        $res = BookingResponse::fromEntity($booking);
        $this->assertSame('bk-full', $res->id);
        $this->assertSame('h-1', $res->source_hold_id);
        $this->assertNotNull($res->checked_in_at);
        $this->assertNotNull($res->completed_at);
        $this->assertSame('completed', $res->status);
    }

    public function testBookingResponseCanceledFlow(): void
    {
        $booking = new Booking(
            'bk-c', $this->org, $this->item, $this->tenant, null,
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 day'),
            1, 'USD', '100.00', '100.00',
        );
        $booking->markCanceled('10.00');

        $res = BookingResponse::fromEntity($booking);
        $this->assertNotNull($res->canceled_at);
        $this->assertSame('10.00', $res->cancellation_fee_amount);
    }

    public function testBookingResponseNoShowFlow(): void
    {
        $booking = new Booking(
            'bk-ns', $this->org, $this->item, $this->tenant, null,
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 day'),
            1, 'USD', '100.00', '100.00',
        );
        $booking->markNoShow('50.00');

        $res = BookingResponse::fromEntity($booking);
        $this->assertNotNull($res->no_show_marked_at);
        $this->assertSame('50.00', $res->no_show_penalty_amount);
    }

    public function testBillResponseWithAllOptionalsPopulated(): void
    {
        $bill = new Bill(
            'b-f', $this->org, null, $this->tenant, BillType::INITIAL, 'USD', '100.00',
            new \DateTimeImmutable('+7 days'),
        );
        // Force status + paid_at / voided_at via reflection
        $r = new \ReflectionProperty($bill, 'status');
        $r->setAccessible(true);
        $r->setValue($bill, BillStatus::PAID);
        $rp = new \ReflectionProperty($bill, 'paidAt');
        $rp->setAccessible(true);
        $rp->setValue($bill, new \DateTimeImmutable());

        $res = BillResponse::fromEntity($bill);
        $this->assertSame('paid', $res->status);
        $this->assertNotNull($res->due_at);
        $this->assertNotNull($res->paid_at);

        $arr = $res->toArray();
        $this->assertSame('paid', $arr['status']);
    }

    public function testBillResponseVoided(): void
    {
        $bill = new Bill('b-v', $this->org, null, $this->tenant, BillType::INITIAL, 'USD', '100.00');
        $r = new \ReflectionProperty($bill, 'status');
        $r->setAccessible(true);
        $r->setValue($bill, BillStatus::VOIDED);
        $rv = new \ReflectionProperty($bill, 'voidedAt');
        $rv->setAccessible(true);
        $rv->setValue($bill, new \DateTimeImmutable());

        $res = BillResponse::fromEntity($bill);
        $this->assertSame('voided', $res->status);
        $this->assertNotNull($res->voided_at);
    }

    public function testBookingHoldResponseWithConfirmedId(): void
    {
        $hold = new BookingHold(
            'h-c', $this->org, $this->item, $this->tenant, 'req-c', 1,
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 day'),
            new \DateTimeImmutable('+15 minutes'),
        );
        $hold->transitionTo(BookingHoldStatus::CONVERTED);
        $hold->setConfirmedBookingId('bk-confirmed');

        $res = BookingHoldResponse::fromEntity($hold);
        $this->assertSame('bk-confirmed', $res->confirmed_booking_id);
        $this->assertSame('converted', $res->status);
    }

    public function testInventoryItemResponseWithAllFields(): void
    {
        $res = InventoryItemResponse::fromEntity($this->item);
        $this->assertSame('it-d', $res->id);
        $this->assertSame('A-1', $res->asset_code);
        $this->assertSame('discrete_units', $res->capacity_mode);
        $arr = $res->toArray();
        $this->assertArrayHasKey('total_capacity', $arr);
    }

    public function testUserResponseWithFrozenAndInactive(): void
    {
        $u = new User('u-fa', $this->org, 'fa', 'h', 'FA', UserRole::FINANCE_CLERK);
        $u->setIsFrozen(true);
        $u->setIsActive(false);

        $res = UserResponse::fromEntity($u);
        $this->assertTrue($res->is_frozen);
        $this->assertFalse($res->is_active);
        $this->assertSame('finance_clerk', $res->role);
    }

    public function testOrganizationResponse(): void
    {
        $res = OrganizationResponse::fromEntity($this->org);
        $this->assertSame('DTO', $res->code);
        $this->assertSame('USD', $res->default_currency);
        $this->assertTrue($res->is_active);
    }

    public function testAuditLogResponseMasksObjectId(): void
    {
        $log = new AuditLog(
            'al-1', $this->org, $this->admin, 'admin', 'BOOKING_CREATED', 'Booking', 'abcdef1234',
        );
        $res = AuditLogResponse::fromEntity($log);
        $this->assertSame('BOOKING_CREATED', $res->action_code);
        // Should be masked except last 4 chars
        $this->assertStringEndsWith('1234', $res->object_id);
        $this->assertStringStartsWith('*', $res->object_id);
    }

    public function testAuditLogResponseShortObjectIdNotMasked(): void
    {
        $log = new AuditLog(
            'al-2', $this->org, $this->admin, 'admin', 'ACTION', 'Thing', 'abc',
        );
        $res = AuditLogResponse::fromEntity($log);
        // Short IDs (<=4 chars) should pass through unmasked per controller logic
        $this->assertSame('abc', $res->object_id);
    }

    public function testRefundResponseWithPaymentId(): void
    {
        $bill = new Bill('b-r', $this->org, null, $this->tenant, BillType::INITIAL, 'USD', '100.00');
        $payment = new Payment('pay-r', $this->org, $bill, 'req-r', 'USD', '50.00');
        $refund = new Refund(
            'ref-r', $this->org, $bill, $payment, '20.00', 'ok',
            RefundStatus::ISSUED, $this->admin,
        );

        $res = RefundResponse::fromEntity($refund);
        $this->assertSame('pay-r', $res->payment_id);
        $this->assertSame('20.00', $res->amount);
    }

    public function testLedgerEntryResponseWithAllRelations(): void
    {
        $booking = new Booking(
            'bk-le', $this->org, $this->item, $this->tenant, null,
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 day'),
            1, 'USD', '100.00', '100.00',
        );
        $bill = new Bill('b-le', $this->org, $booking, $this->tenant, BillType::INITIAL, 'USD', '100.00');
        $payment = new Payment('pay-le', $this->org, $bill, 'req-le', 'USD', '50.00');
        $refund = new Refund('ref-le', $this->org, $bill, $payment, '10.00', 'ok', RefundStatus::ISSUED, $this->admin);

        $entry = new LedgerEntry(
            'le-f', $this->org, LedgerEntryType::REFUND_ISSUED, '10.00', 'USD',
            $booking, $bill, $payment, $refund, ['note' => 'full'],
        );

        $res = LedgerEntryResponse::fromEntity($entry);
        $this->assertSame('bk-le', $res->booking_id);
        $this->assertSame('b-le', $res->bill_id);
        $this->assertSame('pay-le', $res->payment_id);
        $this->assertSame('ref-le', $res->refund_id);
        $this->assertSame(['note' => 'full'], $res->metadata_json);
    }

    public function testPricingResponseWithEffectiveTo(): void
    {
        $pricing = new InventoryPricing(
            'p-et', $this->org, $this->item, RateType::DAILY, '100.00', 'USD',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );
        $res = PricingResponse::fromEntity($pricing);
        $this->assertSame('daily', $res->rate_type);
        $this->assertNotNull($res->effective_to);
    }

    public function testPricingResponseWithoutEffectiveTo(): void
    {
        $pricing = new InventoryPricing(
            'p-no-et', $this->org, $this->item, RateType::HOURLY, '15.00', 'USD',
            new \DateTimeImmutable('2026-01-01'),
        );
        $res = PricingResponse::fromEntity($pricing);
        $this->assertNull($res->effective_to);
    }

    public function testPlaylistResponse(): void
    {
        $pl = new TerminalPlaylist('pl-1', $this->org, 'W', 'HQ', 'MON-FRI');
        $res = PlaylistResponse::fromEntity($pl);
        $this->assertSame('W', $res->name);
        $this->assertSame('MON-FRI', $res->schedule_rule);
    }

    public function testReconciliationRunResponseWithCompletedAt(): void
    {
        $run = new ReconciliationRun('run-c', $this->org, new \DateTimeImmutable('today'));
        $run->markCompleted(3, '/path/to.csv');

        $res = ReconciliationRunResponse::fromEntity($run);
        $this->assertSame(3, $res->mismatch_count);
        $this->assertSame('/path/to.csv', $res->output_csv_path);
        $this->assertNotNull($res->completed_at);
    }

    public function testReconciliationRunResponseInProgress(): void
    {
        $run = new ReconciliationRun('run-ip', $this->org, new \DateTimeImmutable('today'));
        $res = ReconciliationRunResponse::fromEntity($run);
        $this->assertNull($res->completed_at);
        $this->assertNull($res->output_csv_path);
    }

    public function testTransferResponseWithCompletedAt(): void
    {
        $terminal = new Terminal('t-r', $this->org, 'T-R', 'L', 'HQ');
        $tr = new TerminalPackageTransfer('tx-r', $this->org, $terminal, 'p.zip', 'ck', 2);
        $tr->transitionTo(TerminalTransferStatus::IN_PROGRESS);
        $tr->incrementChunks();
        $tr->incrementChunks();
        $tr->transitionTo(TerminalTransferStatus::COMPLETED);

        $res = TransferResponse::fromEntity($tr);
        $this->assertSame('completed', $res->status);
        $this->assertNotNull($res->completed_at);
    }

    public function testNotificationPreferenceResponse(): void
    {
        $pref = new NotificationPreference('np-1', $this->tenant, 'booking.confirmed');
        $res = NotificationPreferenceResponse::fromEntity($pref);
        $this->assertSame('u-t', $res->user_id);
        $this->assertSame('booking.confirmed', $res->event_code);
    }

    // ═══════════════════════════════════════════════════════════════
    // toArray() coverage for every DTO with that method.
    // ═══════════════════════════════════════════════════════════════

    public function testBookingResponseToArray(): void
    {
        $booking = new Booking(
            'bk-ta', $this->org, $this->item, $this->tenant, null,
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 day'),
            1, 'USD', '100.00', '100.00',
        );
        $arr = BookingResponse::fromEntity($booking)->toArray();
        foreach ([
            'id', 'organization_id', 'inventory_item_id', 'tenant_user_id',
            'source_hold_id', 'status', 'start_at', 'end_at', 'booked_units',
            'currency', 'base_amount', 'final_amount', 'cancellation_fee_amount',
            'no_show_penalty_amount', 'created_at', 'updated_at',
            'canceled_at', 'completed_at', 'no_show_marked_at', 'checked_in_at',
        ] as $k) {
            $this->assertArrayHasKey($k, $arr);
        }
    }

    public function testUserResponseToArray(): void
    {
        $arr = UserResponse::fromEntity($this->admin)->toArray();
        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('username', $arr);
        $this->assertArrayHasKey('role', $arr);
    }

    public function testOrganizationResponseToArray(): void
    {
        $arr = OrganizationResponse::fromEntity($this->org)->toArray();
        $this->assertSame('DTO', $arr['code']);
        $this->assertSame('USD', $arr['default_currency']);
    }

    public function testAuditLogResponseToArray(): void
    {
        $log = new AuditLog('al-ta', $this->org, $this->admin, 'a', 'X', 'Thing', 'abcdef1234');
        $arr = AuditLogResponse::fromEntity($log)->toArray();
        $this->assertArrayHasKey('action_code', $arr);
        $this->assertArrayHasKey('object_id', $arr);
    }

    public function testInventoryItemResponseToArray(): void
    {
        $arr = InventoryItemResponse::fromEntity($this->item)->toArray();
        $this->assertArrayHasKey('total_capacity', $arr);
        $this->assertArrayHasKey('capacity_mode', $arr);
        $this->assertArrayHasKey('timezone', $arr);
    }

    public function testPricingResponseToArray(): void
    {
        $p = new InventoryPricing(
            'p-ta', $this->org, $this->item, RateType::DAILY, '50.00', 'USD',
            new \DateTimeImmutable('2026-01-01'),
        );
        $arr = PricingResponse::fromEntity($p)->toArray();
        $this->assertSame('daily', $arr['rate_type']);
        $this->assertNull($arr['effective_to']);
    }

    public function testReconciliationRunResponseToArray(): void
    {
        $run = new ReconciliationRun('r-ta', $this->org, new \DateTimeImmutable());
        $arr = ReconciliationRunResponse::fromEntity($run)->toArray();
        $this->assertArrayHasKey('status', $arr);
        $this->assertArrayHasKey('mismatch_count', $arr);
    }

    public function testPlaylistResponseToArray(): void
    {
        $pl = new TerminalPlaylist('pl-ta', $this->org, 'N', 'G', 'rule');
        $arr = PlaylistResponse::fromEntity($pl)->toArray();
        $this->assertSame('N', $arr['name']);
    }

    public function testRefundResponseToArray(): void
    {
        $bill = new Bill('b-ta', $this->org, null, $this->tenant, BillType::INITIAL, 'USD', '100.00');
        $refund = new Refund('r-ta-2', $this->org, $bill, null, '10.00', 'x', RefundStatus::ISSUED, $this->admin);
        $arr = RefundResponse::fromEntity($refund)->toArray();
        $this->assertArrayHasKey('amount', $arr);
        $this->assertArrayHasKey('reason', $arr);
        $this->assertNull($arr['payment_id']);
    }

    public function testBookingHoldResponseToArray(): void
    {
        $hold = new BookingHold(
            'h-ta', $this->org, $this->item, $this->tenant, 'req', 1,
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 day'),
            new \DateTimeImmutable('+15 minutes'),
        );
        $arr = BookingHoldResponse::fromEntity($hold)->toArray();
        $this->assertArrayHasKey('held_units', $arr);
        $this->assertArrayHasKey('expires_at', $arr);
    }

    public function testLedgerEntryResponseToArray(): void
    {
        $entry = new LedgerEntry(
            'le-ta', $this->org, LedgerEntryType::PAYMENT_RECEIVED, '50.00', 'USD',
        );
        $arr = LedgerEntryResponse::fromEntity($entry)->toArray();
        $this->assertArrayHasKey('entry_type', $arr);
        $this->assertArrayHasKey('amount', $arr);
        $this->assertNull($arr['booking_id']);
        $this->assertNull($arr['metadata_json']);
    }

    public function testTransferResponseToArray(): void
    {
        $terminal = new Terminal('t-ta', $this->org, 'T-TA', 'L', 'G');
        $tr = new TerminalPackageTransfer('tx-ta', $this->org, $terminal, 'p.zip', 'ck', 2);
        $arr = TransferResponse::fromEntity($tr)->toArray();
        $this->assertArrayHasKey('total_chunks', $arr);
        $this->assertArrayHasKey('transferred_chunks', $arr);
    }
}
