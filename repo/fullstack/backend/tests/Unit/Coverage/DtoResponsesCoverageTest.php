<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\DTO\Response\BillResponse;
use App\DTO\Response\BookingHoldResponse;
use App\DTO\Response\BookingResponse;
use App\DTO\Response\LedgerEntryResponse;
use App\DTO\Response\NotificationResponse;
use App\DTO\Response\PaymentResponse;
use App\DTO\Response\SettingsResponse;
use App\DTO\Response\TerminalResponse;
use App\DTO\Response\TransferResponse;
use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\BookingHold;
use App\Entity\InventoryItem;
use App\Entity\LedgerEntry;
use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Entity\Refund;
use App\Entity\Settings;
use App\Entity\Terminal;
use App\Entity\TerminalPackageTransfer;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\BookingHoldStatus;
use App\Enum\BookingStatus;
use App\Enum\LedgerEntryType;
use App\Enum\NotificationStatus;
use App\Enum\PaymentStatus;
use App\Enum\TerminalTransferStatus;
use PHPUnit\Framework\TestCase;

class DtoResponsesCoverageTest extends TestCase
{
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-04-15T10:00:00+00:00');
    }

    // ═══════════════════════════════════════════════════════════════
    // BookingResponse
    // ═══════════════════════════════════════════════════════════════

    public function testBookingResponseFromEntity(): void
    {
        $booking = $this->createMock(Booking::class);
        $booking->method('getId')->willReturn('bk-1');
        $booking->method('getOrganizationId')->willReturn('org-1');
        $booking->method('getInventoryItemId')->willReturn('item-1');
        $booking->method('getTenantUserId')->willReturn('user-1');
        $booking->method('getSourceHold')->willReturn(null);
        $booking->method('getStatus')->willReturn(BookingStatus::CONFIRMED);
        $booking->method('getStartAt')->willReturn($this->now());
        $booking->method('getEndAt')->willReturn($this->now()->modify('+1 day'));
        $booking->method('getBookedUnits')->willReturn(1);
        $booking->method('getCurrency')->willReturn('USD');
        $booking->method('getBaseAmount')->willReturn('100.00');
        $booking->method('getFinalAmount')->willReturn('100.00');
        $booking->method('getCancellationFeeAmount')->willReturn('0.00');
        $booking->method('getNoShowPenaltyAmount')->willReturn('0.00');
        $booking->method('getCreatedAt')->willReturn($this->now());
        $booking->method('getUpdatedAt')->willReturn($this->now());
        $booking->method('getCanceledAt')->willReturn(null);
        $booking->method('getCompletedAt')->willReturn(null);
        $booking->method('getNoShowMarkedAt')->willReturn(null);
        $booking->method('getCheckedInAt')->willReturn(null);

        $res = BookingResponse::fromEntity($booking);
        $this->assertSame('bk-1', $res->id);
        $this->assertSame('confirmed', $res->status);
        $arr = (array) $res;
        $this->assertArrayHasKey('id', $arr);
    }

    // ═══════════════════════════════════════════════════════════════
    // BillResponse
    // ═══════════════════════════════════════════════════════════════

    public function testBillResponseFromEntityAndToArray(): void
    {
        $bill = $this->createMock(Bill::class);
        $bill->method('getId')->willReturn('b-1');
        $bill->method('getOrganizationId')->willReturn('org-1');
        $bill->method('getBookingId')->willReturn('bk-1');
        $bill->method('getTenantUserId')->willReturn('u-1');
        $bill->method('getBillType')->willReturn(BillType::INITIAL);
        $bill->method('getStatus')->willReturn(BillStatus::OPEN);
        $bill->method('getCurrency')->willReturn('USD');
        $bill->method('getOriginalAmount')->willReturn('100.00');
        $bill->method('getOutstandingAmount')->willReturn('100.00');
        $bill->method('getDueAt')->willReturn(null);
        $bill->method('getIssuedAt')->willReturn($this->now());
        $bill->method('getPaidAt')->willReturn(null);
        $bill->method('getVoidedAt')->willReturn(null);
        $bill->method('getPdfPath')->willReturn('/path/to.pdf');

        $res = BillResponse::fromEntity($bill);
        $this->assertSame('b-1', $res->id);
        $arr = $res->toArray();
        $this->assertSame('100.00', $arr['original_amount']);
        $this->assertSame('/path/to.pdf', $arr['pdf_path']);
    }

    // ═══════════════════════════════════════════════════════════════
    // BookingHoldResponse
    // ═══════════════════════════════════════════════════════════════

    public function testBookingHoldResponseFromEntityAndToArray(): void
    {
        $hold = $this->createMock(BookingHold::class);
        $hold->method('getId')->willReturn('h-1');
        $hold->method('getOrganizationId')->willReturn('org-1');
        $hold->method('getInventoryItemId')->willReturn('item-1');
        $hold->method('getTenantUserId')->willReturn('u-1');
        $hold->method('getRequestKey')->willReturn('req-1');
        $hold->method('getHeldUnits')->willReturn(2);
        $hold->method('getStartAt')->willReturn($this->now());
        $hold->method('getEndAt')->willReturn($this->now());
        $hold->method('getExpiresAt')->willReturn($this->now());
        $hold->method('getStatus')->willReturn(BookingHoldStatus::ACTIVE);
        $hold->method('getConfirmedBookingId')->willReturn(null);
        $hold->method('getCreatedAt')->willReturn($this->now());

        $res = BookingHoldResponse::fromEntity($hold);
        $this->assertSame('h-1', $res->id);
        $arr = $res->toArray();
        $this->assertSame(2, $arr['held_units']);
        $this->assertSame('active', $arr['status']);
    }

    // ═══════════════════════════════════════════════════════════════
    // PaymentResponse
    // ═══════════════════════════════════════════════════════════════

    public function testPaymentResponseFromEntityAndToArray(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn('pay-1');
        $payment->method('getOrganizationId')->willReturn('org-1');
        $payment->method('getBillId')->willReturn('b-1');
        $payment->method('getRequestId')->willReturn('req-1');
        $payment->method('getExternalReference')->willReturn('EXT-99');
        $payment->method('getStatus')->willReturn(PaymentStatus::SUCCEEDED);
        $payment->method('getCurrency')->willReturn('USD');
        $payment->method('getAmount')->willReturn('100.00');
        $payment->method('isSignatureVerified')->willReturn(true);
        $payment->method('getReceivedAt')->willReturn($this->now());
        $payment->method('getProcessedAt')->willReturn($this->now());
        $payment->method('getCreatedAt')->willReturn($this->now());

        $res = PaymentResponse::fromEntity($payment);
        $this->assertSame('pay-1', $res->id);
        $arr = $res->toArray();
        $this->assertTrue($arr['signature_verified']);
        $this->assertSame('EXT-99', $arr['external_reference']);
    }

    // ═══════════════════════════════════════════════════════════════
    // LedgerEntryResponse
    // ═══════════════════════════════════════════════════════════════

    public function testLedgerEntryResponseFromEntity(): void
    {
        $booking = $this->createMock(Booking::class);
        $booking->method('getId')->willReturn('bk-1');
        $bill = $this->createMock(Bill::class);
        $bill->method('getId')->willReturn('b-1');
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn('pay-1');
        $refund = $this->createMock(Refund::class);
        $refund->method('getId')->willReturn('r-1');

        $entry = $this->createMock(LedgerEntry::class);
        $entry->method('getId')->willReturn('le-1');
        $entry->method('getOrganizationId')->willReturn('org-1');
        $entry->method('getBooking')->willReturn($booking);
        $entry->method('getBill')->willReturn($bill);
        $entry->method('getPayment')->willReturn($payment);
        $entry->method('getRefund')->willReturn($refund);
        $entry->method('getEntryType')->willReturn(LedgerEntryType::PAYMENT_RECEIVED);
        $entry->method('getAmount')->willReturn('50.00');
        $entry->method('getCurrency')->willReturn('USD');
        $entry->method('getOccurredAt')->willReturn($this->now());
        $entry->method('getMetadataJson')->willReturn(['note' => 'test']);

        $res = LedgerEntryResponse::fromEntity($entry);
        $this->assertSame('le-1', $res->id);
        $this->assertSame('bk-1', $res->booking_id);
        $arr = $res->toArray();
        $this->assertSame(['note' => 'test'], $arr['metadata_json']);
    }

    public function testLedgerEntryResponseNullRelations(): void
    {
        $entry = $this->createMock(LedgerEntry::class);
        $entry->method('getId')->willReturn('le-2');
        $entry->method('getOrganizationId')->willReturn('org-1');
        $entry->method('getBooking')->willReturn(null);
        $entry->method('getBill')->willReturn(null);
        $entry->method('getPayment')->willReturn(null);
        $entry->method('getRefund')->willReturn(null);
        $entry->method('getEntryType')->willReturn(LedgerEntryType::BILL_ISSUED);
        $entry->method('getAmount')->willReturn('10.00');
        $entry->method('getCurrency')->willReturn('USD');
        $entry->method('getOccurredAt')->willReturn($this->now());
        $entry->method('getMetadataJson')->willReturn(null);

        $res = LedgerEntryResponse::fromEntity($entry);
        $this->assertNull($res->booking_id);
        $this->assertNull($res->bill_id);
        $this->assertNull($res->payment_id);
        $this->assertNull($res->refund_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // NotificationResponse
    // ═══════════════════════════════════════════════════════════════

    public function testNotificationResponseFromEntityAndToArray(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $notif = $this->createMock(Notification::class);
        $notif->method('getId')->willReturn('n-1');
        $notif->method('getOrganization')->willReturn($org);
        $notif->method('getUserId')->willReturn('u-1');
        $notif->method('getEventCode')->willReturn('booking_confirmed');
        $notif->method('getTitle')->willReturn('Hello');
        $notif->method('getBody')->willReturn('World');
        $notif->method('getStatus')->willReturn(NotificationStatus::PENDING);
        $notif->method('getScheduledFor')->willReturn($this->now());
        $notif->method('getDeliveredAt')->willReturn(null);
        $notif->method('getReadAt')->willReturn(null);
        $notif->method('getCreatedAt')->willReturn($this->now());

        $res = NotificationResponse::fromEntity($notif);
        $this->assertSame('n-1', $res->id);
        $arr = $res->toArray();
        $this->assertSame('Hello', $arr['title']);
    }

    // ═══════════════════════════════════════════════════════════════
    // TerminalResponse
    // ═══════════════════════════════════════════════════════════════

    public function testTerminalResponseFromEntityAndToArray(): void
    {
        $t = $this->createMock(Terminal::class);
        $t->method('getId')->willReturn('term-1');
        $t->method('getOrganizationId')->willReturn('org-1');
        $t->method('getTerminalCode')->willReturn('T-01');
        $t->method('getDisplayName')->willReturn('Lobby');
        $t->method('getLocationGroup')->willReturn('HQ');
        $t->method('getLanguageCode')->willReturn('en');
        $t->method('getAccessibilityMode')->willReturn(false);
        $t->method('isActive')->willReturn(true);
        $t->method('getLastSyncAt')->willReturn(null);
        $t->method('getCreatedAt')->willReturn($this->now());
        $t->method('getUpdatedAt')->willReturn($this->now());

        $res = TerminalResponse::fromEntity($t);
        $this->assertSame('term-1', $res->id);
        $arr = $res->toArray();
        $this->assertTrue($arr['is_active']);
    }

    // ═══════════════════════════════════════════════════════════════
    // TransferResponse
    // ═══════════════════════════════════════════════════════════════

    public function testTransferResponseFromEntityAndToArray(): void
    {
        $tr = $this->createMock(TerminalPackageTransfer::class);
        $tr->method('getId')->willReturn('tr-1');
        $tr->method('getOrganizationId')->willReturn('org-1');
        $tr->method('getTerminalId')->willReturn('term-1');
        $tr->method('getPackageName')->willReturn('pkg.zip');
        $tr->method('getChecksum')->willReturn('abc123');
        $tr->method('getTotalChunks')->willReturn(10);
        $tr->method('getTransferredChunks')->willReturn(5);
        $tr->method('getStatus')->willReturn(TerminalTransferStatus::IN_PROGRESS);
        $tr->method('getStartedAt')->willReturn($this->now());
        $tr->method('getCompletedAt')->willReturn(null);

        $res = TransferResponse::fromEntity($tr);
        $this->assertSame('tr-1', $res->id);
        $arr = $res->toArray();
        $this->assertSame(5, $arr['transferred_chunks']);
    }

    // ═══════════════════════════════════════════════════════════════
    // SettingsResponse
    // ═══════════════════════════════════════════════════════════════

    public function testSettingsResponseFromEntity(): void
    {
        $s = $this->createMock(Settings::class);
        $s->method('getId')->willReturn('s-1');
        $s->method('getOrganizationId')->willReturn('org-1');
        $s->method('getTimezone')->willReturn('UTC');
        $s->method('getAllowPartialPayments')->willReturn(true);
        $s->method('getCancellationFeePct')->willReturn('10.00');
        $s->method('getNoShowFeePct')->willReturn('25.00');
        $s->method('getNoShowFirstDayRentEnabled')->willReturn(false);
        $s->method('getHoldDurationMinutes')->willReturn(15);
        $s->method('getNoShowGracePeriodMinutes')->willReturn(30);
        $s->method('getMaxDevicesPerUser')->willReturn(3);
        $s->method('getRecurringBillDay')->willReturn(1);
        $s->method('getRecurringBillHour')->willReturn(9);
        $s->method('getBookingAttemptsPerItemPerMinute')->willReturn(10);
        $s->method('getMaxBookingDurationDays')->willReturn(365);
        $s->method('getTerminalsEnabled')->willReturn(false);
        $s->method('getCreatedAt')->willReturn($this->now());
        $s->method('getUpdatedAt')->willReturn($this->now());

        $res = SettingsResponse::fromEntity($s);
        $this->assertSame('UTC', $res->timezone);
        $arr = $res->toArray();
        $this->assertArrayHasKey('timezone', $arr);
    }
}
