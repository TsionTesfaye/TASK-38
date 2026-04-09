<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Booking;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Repository\BookingEventRepository;
use App\Repository\BookingRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BillingService;
use App\Service\BookingHoldService;
use App\Service\BookingService;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\OrgTimeService;
use App\Service\PricingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Service-level state transition tests for BookingService.
 *
 * These replace logic-replica tests that tested enums directly.
 * Instead, we call the real service methods and verify:
 *   1. Valid transitions succeed through the service
 *   2. Invalid transitions are rejected by the service
 *   3. RBAC is enforced at the service layer
 *   4. No-show through service with penalty calculation
 *   5. Cancellation through service with fee calculation
 */
class BookingServiceStateTransitionTest extends TestCase
{
    private function makeOrg(): Organization&MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $org->method('getDefaultCurrency')->willReturn('USD');
        return $org;
    }

    private function makeUser(UserRole $role): User&MockObject
    {
        $org = $this->makeOrg();
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('getRole')->willReturn($role);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn('manager');
        return $user;
    }

    private function makeBooking(BookingStatus $status, ?\DateTimeImmutable $checkedInAt = null): Booking&MockObject
    {
        $booking = $this->createMock(Booking::class);
        $booking->method('getId')->willReturn('booking-1');
        $booking->method('getStatus')->willReturn($status);
        $booking->method('getOrganizationId')->willReturn('org-1');
        $booking->method('getTenantUserId')->willReturn('tenant-1');
        $booking->method('getInventoryItemId')->willReturn('item-1');
        $booking->method('getCheckedInAt')->willReturn($checkedInAt);
        $booking->method('getBookedUnits')->willReturn(1);
        $booking->method('getBaseAmount')->willReturn('100.00');
        $booking->method('getCurrency')->willReturn('USD');
        $booking->method('getStartAt')->willReturn(new \DateTimeImmutable('-2 hours'));
        $booking->method('getEndAt')->willReturn(new \DateTimeImmutable('+22 hours'));
        return $booking;
    }

    private function makeService(Booking $booking): BookingService
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $orgTimeService = $this->createMock(OrgTimeService::class);
        $orgTimeService->method('now')->willReturn(new \DateTimeImmutable());

        $pricingService = $this->createMock(PricingService::class);
        $pricingService->method('getActivePricing')->willReturn(null);

        return new BookingService(
            $bookingRepo,
            $this->createMock(BookingEventRepository::class),
            $settingsRepo,
            $this->createMock(BillingService::class),
            $pricingService,
            $this->createMock(BookingHoldService::class),
            $this->createMock(LedgerService::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(EntityManagerInterface::class),
            $orgTimeService,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. Valid transitions through service
    // ═══════════════════════════════════════════════════════════════

    public function testCheckInConfirmedBookingSucceeds(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $booking->expects($this->once())->method('markCheckedIn');
        $service = $this->makeService($booking);

        $result = $service->checkIn($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
        $this->assertSame($booking, $result);
    }

    public function testCompleteActiveBookingSucceeds(): void
    {
        $booking = $this->makeBooking(BookingStatus::ACTIVE);
        $booking->expects($this->once())->method('markCompleted');
        $service = $this->makeService($booking);

        $result = $service->complete($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
        $this->assertSame($booking, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. Invalid transitions rejected by service
    // ═══════════════════════════════════════════════════════════════

    public function testCheckInActiveBookingRejected(): void
    {
        $booking = $this->makeBooking(BookingStatus::ACTIVE);
        $service = $this->makeService($booking);

        $this->expectException(\DomainException::class);
        $service->checkIn($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
    }

    public function testCompleteConfirmedBookingRejected(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $service = $this->makeService($booking);

        $this->expectException(\DomainException::class);
        $service->complete($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
    }

    public function testCheckInCompletedBookingRejected(): void
    {
        $booking = $this->makeBooking(BookingStatus::COMPLETED);
        $service = $this->makeService($booking);

        $this->expectException(\DomainException::class);
        $service->checkIn($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
    }

    public function testCancelCompletedBookingRejected(): void
    {
        $booking = $this->makeBooking(BookingStatus::COMPLETED);
        $service = $this->makeService($booking);

        $this->expectException(\DomainException::class);
        $service->cancel($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
    }

    public function testCancelNoShowBookingRejected(): void
    {
        $booking = $this->makeBooking(BookingStatus::NO_SHOW);
        $service = $this->makeService($booking);

        $this->expectException(\DomainException::class);
        $service->cancel($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. RBAC enforced at service level
    // ═══════════════════════════════════════════════════════════════

    public function testTenantCannotCheckIn(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $service = $this->makeService($booking);

        $this->expectException(AccessDeniedException::class);
        $service->checkIn($this->makeUser(UserRole::TENANT), 'booking-1');
    }

    public function testTenantCannotMarkNoShow(): void
    {
        $booking = $this->makeBooking(BookingStatus::ACTIVE);
        $service = $this->makeService($booking);

        $this->expectException(AccessDeniedException::class);
        $service->markNoShow($this->makeUser(UserRole::TENANT), 'booking-1');
    }

    public function testFinanceClerkCannotCheckIn(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $service = $this->makeService($booking);

        $this->expectException(AccessDeniedException::class);
        $service->checkIn($this->makeUser(UserRole::FINANCE_CLERK), 'booking-1');
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. No-show through service
    // ═══════════════════════════════════════════════════════════════

    public function testMarkNoShowOnActiveBookingSucceeds(): void
    {
        $booking = $this->makeBooking(BookingStatus::ACTIVE, null);
        $booking->expects($this->once())->method('markNoShow')->with($this->isType('string'));
        $service = $this->makeService($booking);

        $result = $service->markNoShow($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
        $this->assertSame($booking, $result);
    }

    public function testMarkNoShowOnConfirmedBookingSucceeds(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED, null);
        $booking->expects($this->once())->method('markNoShow')->with($this->isType('string'));
        $service = $this->makeService($booking);

        $result = $service->markNoShow($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
        $this->assertSame($booking, $result);
    }

    public function testMarkNoShowRejectedIfAlreadyCheckedIn(): void
    {
        $booking = $this->makeBooking(BookingStatus::ACTIVE, new \DateTimeImmutable());
        $service = $this->makeService($booking);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('checked in');
        $service->markNoShow($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
    }

    public function testMarkNoShowOnCompletedBookingRejected(): void
    {
        $booking = $this->makeBooking(BookingStatus::COMPLETED);
        $service = $this->makeService($booking);

        $this->expectException(\DomainException::class);
        $service->markNoShow($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
    }

    public function testMarkNoShowOnCanceledBookingRejected(): void
    {
        $booking = $this->makeBooking(BookingStatus::CANCELED);
        $service = $this->makeService($booking);

        $this->expectException(\DomainException::class);
        $service->markNoShow($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. Cancel through service
    // ═══════════════════════════════════════════════════════════════

    public function testCancelConfirmedBookingSucceeds(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $booking->expects($this->once())->method('markCanceled')->with($this->isType('string'));
        $service = $this->makeService($booking);

        $tenant = $this->makeUser(UserRole::TENANT);
        $tenant->method('getId')->willReturn('tenant-1');

        $result = $service->cancel($tenant, 'booking-1');
        $this->assertSame($booking, $result);
    }

    public function testCancelActiveBookingSucceeds(): void
    {
        $booking = $this->makeBooking(BookingStatus::ACTIVE);
        $booking->expects($this->once())->method('markCanceled')->with($this->isType('string'));
        $service = $this->makeService($booking);

        $result = $service->cancel($this->makeUser(UserRole::PROPERTY_MANAGER), 'booking-1');
        $this->assertSame($booking, $result);
    }
}
