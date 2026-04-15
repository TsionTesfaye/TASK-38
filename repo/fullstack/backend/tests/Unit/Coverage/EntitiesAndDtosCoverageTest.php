<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\DTO\Response\AuthTokenResponse;
use App\DTO\Response\BillResponse;
use App\DTO\Response\BookingHoldResponse;
use App\DTO\Response\BookingResponse;
use App\DTO\Response\ErrorResponse;
use App\DTO\Response\HealthResponse;
use App\DTO\Response\InventoryItemResponse;
use App\DTO\Response\LedgerEntryResponse;
use App\DTO\Response\NotificationPreferenceResponse;
use App\DTO\Response\NotificationResponse;
use App\DTO\Response\OrganizationResponse;
use App\DTO\Response\PaymentResponse;
use App\DTO\Response\PricingResponse;
use App\DTO\Response\RefundResponse;
use App\DTO\Response\SettingsResponse;
use App\DTO\Response\UserResponse;
use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\BookingHold;
use App\Entity\DeviceSession;
use App\Entity\InventoryItem;
use App\Entity\InventoryPricing;
use App\Entity\LedgerEntry;
use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Entity\Refund;
use App\Entity\Settings;
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
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive coverage for entity getters/setters, state-machine methods,
 * JSON serialization, and DTO Response conversion.
 */
class EntitiesAndDtosCoverageTest extends TestCase
{
    private function makeOrg(): Organization
    {
        return new Organization('org-1', 'ORG', 'Test Org');
    }

    private function makeUser(UserRole $role = UserRole::TENANT): User
    {
        return new User('user-1', $this->makeOrg(), 'testuser', 'hash', 'Test User', $role);
    }

    // ── Organization ──────────────────────────────────────────────

    public function testOrganizationEntity(): void
    {
        $org = new Organization('org-1', 'ORG', 'Test Org');
        $this->assertSame('org-1', $org->getId());
        $this->assertSame('ORG', $org->getCode());
        $this->assertSame('Test Org', $org->getName());
        $this->assertTrue($org->isActive());

        $org->setIsActive(false);
        $this->assertFalse($org->isActive());

        $org->setDefaultCurrency('EUR');
        $this->assertSame('EUR', $org->getDefaultCurrency());

        $json = $org->jsonSerialize();
        $this->assertSame('org-1', $json['id']);
        $this->assertSame('ORG', $json['code']);
        $this->assertSame('EUR', $json['default_currency']);

        // DTO
        $dto = OrganizationResponse::fromEntity($org);
        $arr = $dto->toArray();
        $this->assertSame('org-1', $arr['id']);
        $this->assertSame('EUR', $arr['default_currency']);
    }

    // ── User ──────────────────────────────────────────────────────

    public function testUserEntityAndDto(): void
    {
        $user = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->assertSame('user-1', $user->getId());
        $this->assertSame('testuser', $user->getUsername());
        $this->assertSame('Test User', $user->getDisplayName());
        $this->assertSame(UserRole::ADMINISTRATOR, $user->getRole());
        $this->assertSame('hash', $user->getPasswordHash());
        $this->assertSame('org-1', $user->getOrganizationId());
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isFrozen());
        $this->assertNull($user->getPasswordChangedAt());

        $user->setDisplayName('New Name');
        $this->assertSame('New Name', $user->getDisplayName());

        $user->setIsActive(false);
        $this->assertFalse($user->isActive());

        $user->setIsFrozen(true);
        $this->assertTrue($user->isFrozen());

        $user->setPasswordHash('new_hash');
        $this->assertSame('new_hash', $user->getPasswordHash());
        $this->assertNotNull($user->getPasswordChangedAt());

        // UserInterface methods
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertSame('user-1', $user->getUserIdentifier());
        $user->eraseCredentials(); // void

        // JSON serialize — MUST NOT leak password hash
        $json = $user->jsonSerialize();
        $this->assertArrayNotHasKey('password_hash', $json);
        $this->assertArrayNotHasKey('passwordHash', $json);
        $this->assertStringNotContainsString('new_hash', json_encode($json));

        // DTO
        $dto = UserResponse::fromEntity($user);
        $arr = $dto->toArray();
        $this->assertSame('user-1', $arr['id']);
        $this->assertSame('administrator', $arr['role']);
        $this->assertFalse($arr['is_active']);
        $this->assertTrue($arr['is_frozen']);
    }

    // ── DeviceSession ─────────────────────────────────────────────

    public function testDeviceSessionEntity(): void
    {
        $user = $this->makeUser();
        $expires = new \DateTimeImmutable('+1 hour');
        $session = new DeviceSession('s-1', $user, 'refresh_hash', 'iphone', 'client-1', $expires);

        $this->assertSame('s-1', $session->getId());
        $this->assertSame($user, $session->getUser());
        $this->assertSame('user-1', $session->getUserId());
        $this->assertSame('refresh_hash', $session->getRefreshTokenHash());
        $this->assertSame('iphone', $session->getDeviceLabel());
        $this->assertSame('client-1', $session->getClientDeviceId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $session->getIssuedAt());
        $this->assertSame($expires, $session->getExpiresAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $session->getLastSeenAt());
        $this->assertNull($session->getRevokedAt());

        $this->assertTrue($session->isActive());
        $this->assertFalse($session->isExpired());
        $this->assertFalse($session->isRevoked());

        $session->updateLastSeen();

        $session->revoke();
        $this->assertNotNull($session->getRevokedAt());
        $this->assertTrue($session->isRevoked());
        $this->assertFalse($session->isActive());

        // Idempotent revoke
        $firstRevokeAt = $session->getRevokedAt();
        $session->revoke();
        $this->assertSame($firstRevokeAt, $session->getRevokedAt());

        // JSON — MUST NOT leak refresh_token_hash
        $json = $session->jsonSerialize();
        $this->assertArrayNotHasKey('refresh_token_hash', $json);
        $this->assertArrayNotHasKey('refreshTokenHash', $json);
        $this->assertStringNotContainsString('refresh_hash', json_encode($json));
    }

    public function testExpiredDeviceSession(): void
    {
        $user = $this->makeUser();
        $pastExpiry = new \DateTimeImmutable('-1 hour');
        $session = new DeviceSession('s-2', $user, 'h', 'd', 'c', $pastExpiry);

        $this->assertTrue($session->isExpired());
        $this->assertFalse($session->isActive());
    }

    // ── InventoryItem ─────────────────────────────────────────────

    public function testInventoryItemEntity(): void
    {
        $org = $this->makeOrg();
        $item = new InventoryItem(
            'item-1', $org, 'ASSET-1', 'Studio A', 'studio',
            'Building A', CapacityMode::DISCRETE_UNITS, 5, 'UTC',
        );

        $this->assertSame('item-1', $item->getId());
        $this->assertSame('ASSET-1', $item->getAssetCode());
        $this->assertSame('Studio A', $item->getName());
        $this->assertSame('studio', $item->getAssetType());
        $this->assertSame('Building A', $item->getLocationName());
        $this->assertSame(CapacityMode::DISCRETE_UNITS, $item->getCapacityMode());
        $this->assertSame(5, $item->getTotalCapacity());
        $this->assertSame('UTC', $item->getTimezone());
        $this->assertTrue($item->isActive());
        $this->assertSame('org-1', $item->getOrganizationId());

        $item->setName('Studio B');
        $this->assertSame('Studio B', $item->getName());
        $item->setLocationName('Building B');
        $this->assertSame('Building B', $item->getLocationName());
        $item->setTotalCapacity(10);
        $this->assertSame(10, $item->getTotalCapacity());
        $item->setTimezone('America/New_York');
        $this->assertSame('America/New_York', $item->getTimezone());
        $item->setIsActive(false);
        $this->assertFalse($item->isActive());

        $json = $item->jsonSerialize();
        $this->assertSame('item-1', $json['id']);
        $this->assertSame('ASSET-1', $json['asset_code']);

        $dto = InventoryItemResponse::fromEntity($item);
        $arr = $dto->toArray();
        $this->assertSame('item-1', $arr['id']);
        $this->assertSame(10, $arr['total_capacity']);
    }

    // ── InventoryPricing ──────────────────────────────────────────

    public function testInventoryPricingEntity(): void
    {
        $org = $this->makeOrg();
        $item = new InventoryItem('item-1', $org, 'A', 'N', 't', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2027-01-01');

        $pricing = new InventoryPricing(
            'p-1', $org, $item, RateType::DAILY, '100.00', 'USD', $from, $to,
        );

        $this->assertSame('p-1', $pricing->getId());
        $this->assertSame($org, $pricing->getOrganization());
        $this->assertSame($item, $pricing->getInventoryItem());
        $this->assertSame(RateType::DAILY, $pricing->getRateType());
        $this->assertSame('100.00', $pricing->getAmount());
        $this->assertSame('USD', $pricing->getCurrency());
        $this->assertSame($from, $pricing->getEffectiveFrom());
        $this->assertSame($to, $pricing->getEffectiveTo());

        $json = $pricing->jsonSerialize();
        $this->assertSame('p-1', $json['id']);
        $this->assertSame('daily', $json['rate_type']);

        $dto = PricingResponse::fromEntity($pricing);
        $this->assertSame('p-1', $dto->id);
        $this->assertSame('100.00', $dto->amount);
    }

    // ── Booking + BookingHold ─────────────────────────────────────

    public function testBookingHoldEntity(): void
    {
        $org = $this->makeOrg();
        $item = new InventoryItem('item-1', $org, 'A', 'N', 't', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
        $user = $this->makeUser();
        $start = new \DateTimeImmutable('+1 day');
        $end = new \DateTimeImmutable('+2 days');
        $expires = new \DateTimeImmutable('+10 minutes');

        $hold = new BookingHold(
            'hold-1', $org, $item, $user, 'req-1', 1, $start, $end, $expires,
        );

        $this->assertSame('hold-1', $hold->getId());
        $this->assertSame($org, $hold->getOrganization());
        $this->assertSame($item, $hold->getInventoryItem());
        $this->assertSame('item-1', $hold->getInventoryItemId());
        $this->assertSame($user, $hold->getTenantUser());
        $this->assertSame('user-1', $hold->getTenantUserId());
        $this->assertSame('req-1', $hold->getRequestKey());
        $this->assertSame(1, $hold->getHeldUnits());
        $this->assertSame($start, $hold->getStartAt());
        $this->assertSame($end, $hold->getEndAt());
        $this->assertSame($expires, $hold->getExpiresAt());
        $this->assertSame(BookingHoldStatus::ACTIVE, $hold->getStatus());
        $this->assertNull($hold->getConfirmedBookingId());

        $json = $hold->jsonSerialize();
        $this->assertSame('hold-1', $json['id']);
        $this->assertSame('active', $json['status']);

        $dto = BookingHoldResponse::fromEntity($hold);
        $this->assertSame('hold-1', $dto->id);
    }

    public function testBookingEntity(): void
    {
        $org = $this->makeOrg();
        $item = new InventoryItem('item-1', $org, 'A', 'N', 't', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
        $user = $this->makeUser();
        $start = new \DateTimeImmutable('+1 day');
        $end = new \DateTimeImmutable('+2 days');

        $booking = new Booking(
            'booking-1', $org, $item, $user, null, $start, $end, 1, 'USD', '100.00', '100.00',
        );

        $this->assertSame('booking-1', $booking->getId());
        $this->assertSame('org-1', $booking->getOrganizationId());
        $this->assertSame('item-1', $booking->getInventoryItemId());
        $this->assertSame('user-1', $booking->getTenantUserId());
        $this->assertSame(BookingStatus::CONFIRMED, $booking->getStatus());
        $this->assertSame('100.00', $booking->getBaseAmount());
        $this->assertSame('100.00', $booking->getFinalAmount());
        $this->assertSame('0.00', $booking->getCancellationFeeAmount());
        $this->assertSame('0.00', $booking->getNoShowPenaltyAmount());
        $this->assertNull($booking->getCheckedInAt());
        $this->assertNull($booking->getCanceledAt());
        $this->assertNull($booking->getCompletedAt());
        $this->assertNull($booking->getNoShowMarkedAt());
        $this->assertSame('USD', $booking->getCurrency());
        $this->assertSame(1, $booking->getBookedUnits());

        // State transitions
        $booking->markCheckedIn();
        $this->assertSame(BookingStatus::ACTIVE, $booking->getStatus());
        $this->assertNotNull($booking->getCheckedInAt());

        $booking->markCompleted();
        $this->assertSame(BookingStatus::COMPLETED, $booking->getStatus());
        $this->assertNotNull($booking->getCompletedAt());

        $json = $booking->jsonSerialize();
        $this->assertSame('completed', $json['status']);

        $dto = BookingResponse::fromEntity($booking);
        $this->assertSame('booking-1', $dto->id);
    }

    public function testBookingCancelAndNoShow(): void
    {
        $org = $this->makeOrg();
        $item = new InventoryItem('item-1', $org, 'A', 'N', 't', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
        $user = $this->makeUser();

        $b1 = new Booking('b-1', $org, $item, $user, null, new \DateTimeImmutable('+1d'), new \DateTimeImmutable('+2d'), 1, 'USD', '100.00', '100.00');
        $b1->markCanceled('25.00');
        $this->assertSame(BookingStatus::CANCELED, $b1->getStatus());
        $this->assertSame('25.00', $b1->getCancellationFeeAmount());
        $this->assertNotNull($b1->getCanceledAt());

        $b2 = new Booking('b-2', $org, $item, $user, null, new \DateTimeImmutable('+1d'), new \DateTimeImmutable('+2d'), 1, 'USD', '100.00', '100.00');
        $b2->markNoShow('50.00');
        $this->assertSame(BookingStatus::NO_SHOW, $b2->getStatus());
        $this->assertSame('50.00', $b2->getNoShowPenaltyAmount());
        $this->assertNotNull($b2->getNoShowMarkedAt());
    }

    // ── Bill ──────────────────────────────────────────────────────

    public function testBillEntity(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser();
        $item = new InventoryItem('item-1', $org, 'A', 'N', 't', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
        $booking = new Booking('b-1', $org, $item, $user, null, new \DateTimeImmutable('+1d'), new \DateTimeImmutable('+2d'), 1, 'USD', '100.00', '100.00');

        $bill = new Bill(
            'bill-1', $org, $booking, $user, BillType::INITIAL, 'USD', '100.00', null,
        );

        $this->assertSame('bill-1', $bill->getId());
        $this->assertSame('org-1', $bill->getOrganizationId());
        $this->assertSame('b-1', $bill->getBookingId());
        $this->assertSame('user-1', $bill->getTenantUserId());
        $this->assertSame(BillType::INITIAL, $bill->getBillType());
        $this->assertSame(BillStatus::OPEN, $bill->getStatus());
        $this->assertSame('USD', $bill->getCurrency());
        $this->assertSame('100.00', $bill->getOriginalAmount());
        $this->assertSame('100.00', $bill->getOutstandingAmount());

        $bill->setOutstandingAmount('50.00');
        $this->assertSame('50.00', $bill->getOutstandingAmount());

        $json = $bill->jsonSerialize();
        $this->assertSame('bill-1', $json['id']);

        $dto = BillResponse::fromEntity($bill);
        $this->assertSame('bill-1', $dto->id);
    }

    // ── Payment ───────────────────────────────────────────────────

    public function testPaymentEntity(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser();
        $item = new InventoryItem('item-1', $org, 'A', 'N', 't', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
        $booking = new Booking('b-1', $org, $item, $user, null, new \DateTimeImmutable('+1d'), new \DateTimeImmutable('+2d'), 1, 'USD', '100.00', '100.00');
        $bill = new Bill('bill-1', $org, $booking, $user, BillType::INITIAL, 'USD', '100.00', null);

        $payment = new Payment('pay-1', $org, $bill, 'req-1', 'USD', '100.00');

        $this->assertSame('pay-1', $payment->getId());
        $this->assertSame('bill-1', $payment->getBillId());
        $this->assertSame($bill, $payment->getBill());
        $this->assertSame('req-1', $payment->getRequestId());
        $this->assertSame('USD', $payment->getCurrency());
        $this->assertSame('100.00', $payment->getAmount());
        $this->assertSame(PaymentStatus::PENDING, $payment->getStatus());
        $this->assertFalse($payment->isSignatureVerified());
        $this->assertNull($payment->getExternalReference());

        $payment->setSignatureVerified(true);
        $this->assertTrue($payment->isSignatureVerified());

        $payment->setExternalReference('ext-ref');
        $this->assertSame('ext-ref', $payment->getExternalReference());

        $payment->transitionTo(PaymentStatus::SUCCEEDED);
        $this->assertSame(PaymentStatus::SUCCEEDED, $payment->getStatus());

        $json = $payment->jsonSerialize();
        $this->assertSame('pay-1', $json['id']);

        $dto = PaymentResponse::fromEntity($payment);
        $this->assertSame('pay-1', $dto->id);
    }

    // ── Refund ────────────────────────────────────────────────────

    public function testRefundEntity(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser();
        $item = new InventoryItem('item-1', $org, 'A', 'N', 't', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
        $booking = new Booking('b-1', $org, $item, $user, null, new \DateTimeImmutable('+1d'), new \DateTimeImmutable('+2d'), 1, 'USD', '100.00', '100.00');
        $bill = new Bill('bill-1', $org, $booking, $user, BillType::INITIAL, 'USD', '100.00', null);
        $payment = new Payment('pay-1', $org, $bill, 'req-1', 'USD', '100.00');

        $refund = new Refund('r-1', $org, $bill, $payment, '50.00', 'test reason', RefundStatus::ISSUED, $user);
        $this->assertSame('r-1', $refund->getId());
        $this->assertSame('bill-1', $refund->getBillId());
        $this->assertSame('50.00', $refund->getAmount());
        $this->assertSame('test reason', $refund->getReason());
        $this->assertSame(RefundStatus::ISSUED, $refund->getStatus());

        $json = $refund->jsonSerialize();
        $this->assertSame('r-1', $json['id']);

        $dto = RefundResponse::fromEntity($refund);
        $this->assertSame('r-1', $dto->id);
    }

    // ── LedgerEntry ───────────────────────────────────────────────

    public function testLedgerEntryEntity(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser();
        $item = new InventoryItem('item-1', $org, 'A', 'N', 't', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
        $booking = new Booking('b-1', $org, $item, $user, null, new \DateTimeImmutable('+1d'), new \DateTimeImmutable('+2d'), 1, 'USD', '100.00', '100.00');
        $bill = new Bill('bill-1', $org, $booking, $user, BillType::INITIAL, 'USD', '100.00', null);
        $payment = new Payment('pay-1', $org, $bill, 'req-1', 'USD', '100.00');

        $entry = new LedgerEntry(
            'le-1', $org, LedgerEntryType::PAYMENT_RECEIVED, '100.00', 'USD',
            $booking, $bill, $payment, null, ['note' => 'test'],
        );

        $this->assertSame('le-1', $entry->getId());
        $this->assertSame(LedgerEntryType::PAYMENT_RECEIVED, $entry->getEntryType());
        $this->assertSame('100.00', $entry->getAmount());
        $this->assertSame('USD', $entry->getCurrency());
        $this->assertSame($booking, $entry->getBooking());
        $this->assertSame($bill, $entry->getBill());
        $this->assertSame($payment, $entry->getPayment());
        $this->assertNull($entry->getRefund());
        $this->assertSame(['note' => 'test'], $entry->getMetadataJson());

        $json = $entry->jsonSerialize();
        $this->assertSame('payment_received', $json['entry_type']);

        $dto = LedgerEntryResponse::fromEntity($entry);
        $this->assertSame('le-1', $dto->id);
    }

    // ── Notification + Preference ─────────────────────────────────

    public function testNotificationEntity(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser();
        $sched = new \DateTimeImmutable('+1 hour');

        $n = new Notification('n-1', $org, $user, 'booking.confirmed', 'Title', 'Body', $sched);

        $this->assertSame('n-1', $n->getId());
        $this->assertSame('user-1', $n->getUserId());
        $this->assertSame('booking.confirmed', $n->getEventCode());
        $this->assertSame('Title', $n->getTitle());
        $this->assertSame('Body', $n->getBody());
        $this->assertSame($sched, $n->getScheduledFor());
        $this->assertNull($n->getDeliveredAt());
        $this->assertNull($n->getReadAt());

        $n->markDelivered();
        $this->assertNotNull($n->getDeliveredAt());

        $n->markRead();
        $this->assertNotNull($n->getReadAt());

        $json = $n->jsonSerialize();
        $this->assertSame('n-1', $json['id']);

        $dto = NotificationResponse::fromEntity($n);
        $this->assertSame('n-1', $dto->id);
    }

    public function testNotificationPreferenceEntity(): void
    {
        $user = $this->makeUser();
        $pref = new NotificationPreference('p-1', $user, 'booking.confirmed');

        $this->assertSame('p-1', $pref->getId());
        $this->assertSame('booking.confirmed', $pref->getEventCode());
        $this->assertTrue($pref->isEnabled());
        $this->assertSame('21:00', $pref->getDndStartLocal());
        $this->assertSame('08:00', $pref->getDndEndLocal());

        $pref->setIsEnabled(false);
        $this->assertFalse($pref->isEnabled());

        $pref->setDndStartLocal('22:00');
        $this->assertSame('22:00', $pref->getDndStartLocal());

        $pref->setDndEndLocal('07:00');
        $this->assertSame('07:00', $pref->getDndEndLocal());

        $json = $pref->jsonSerialize();
        $this->assertSame('22:00', $json['dnd_start_local']);

        $dto = NotificationPreferenceResponse::fromEntity($pref);
        $this->assertSame('p-1', $dto->id);
    }

    // ── Settings ──────────────────────────────────────────────────

    public function testSettingsEntity(): void
    {
        $org = $this->makeOrg();
        $s = new Settings('s-1', $org);

        $this->assertSame('s-1', $s->getId());
        $this->assertSame('org-1', $s->getOrganizationId());
        $this->assertIsInt($s->getMaxDevicesPerUser());
        $this->assertIsInt($s->getHoldDurationMinutes());
        $this->assertIsString($s->getCancellationFeePct());
        $this->assertIsString($s->getNoShowFeePct());

        $s->setMaxDevicesPerUser(3);
        $this->assertSame(3, $s->getMaxDevicesPerUser());

        $s->setHoldDurationMinutes(15);
        $this->assertSame(15, $s->getHoldDurationMinutes());

        $s->setCancellationFeePct('25.00');
        $this->assertSame('25.00', $s->getCancellationFeePct());

        $s->setNoShowFeePct('60.00');
        $this->assertSame('60.00', $s->getNoShowFeePct());

        $json = $s->jsonSerialize();
        $this->assertSame(3, $json['max_devices_per_user']);

        $dto = SettingsResponse::fromEntity($s);
        $this->assertSame('s-1', $dto->id);
    }

    // ── HealthResponse + ErrorResponse ────────────────────────────

    public function testHealthResponse(): void
    {
        $h = new HealthResponse('ok', ['database' => 'ok']);
        $arr = $h->toArray();
        $this->assertSame('ok', $arr['status']);
        $this->assertSame('ok', $arr['checks']['database']);
    }

    public function testErrorResponse(): void
    {
        $e = new ErrorResponse(400, 'bad request', null);
        $arr = $e->toArray();
        $this->assertSame(400, $arr['code']);
        $this->assertSame('bad request', $arr['message']);
    }

    // ── AuthTokenResponse composition ─────────────────────────────

    public function testAuthTokenResponse(): void
    {
        $user = $this->makeUser();
        $userDto = UserResponse::fromEntity($user);
        $auth = new AuthTokenResponse('access', 'refresh', 900, 'session-1', $userDto);

        $arr = $auth->toArray();
        $this->assertSame('access', $arr['access_token']);
        $this->assertSame('refresh', $arr['refresh_token']);
        $this->assertSame(900, $arr['expires_in']);
        $this->assertSame('session-1', $arr['session_id']);
        $this->assertIsArray($arr['user']);
    }
}
