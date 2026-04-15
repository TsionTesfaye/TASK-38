<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Booking;
use App\Entity\BookingHold;
use App\Entity\InventoryItem;
use App\Entity\Organization;
use App\Entity\Settings;
use App\Entity\User;
use App\Enum\BookingHoldStatus;
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
use PHPUnit\Framework\TestCase;

class BookingServiceHappyPathTest extends TestCase
{
    private function makeService(
        ?BookingRepository $bookingRepo = null,
        ?SettingsRepository $settingsRepo = null,
        ?OrgTimeService $orgTime = null,
        ?OrganizationScope $orgScope = null,
        ?EntityManagerInterface $em = null,
    ): BookingService {
        return new BookingService(
            $bookingRepo ?? $this->createMock(BookingRepository::class),
            $this->createMock(BookingEventRepository::class),
            $settingsRepo ?? $this->createMock(SettingsRepository::class),
            $this->createMock(BillingService::class),
            $this->createMock(PricingService::class),
            $this->createMock(BookingHoldService::class),
            $this->createMock(LedgerService::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $orgScope ?? $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $em ?? $this->createMock(EntityManagerInterface::class),
            $orgTime ?? $this->createMock(OrgTimeService::class),
        );
    }

    private function makeUser(UserRole $role, string $userId = 'user-1', string $orgId = 'org-1'): User
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn($orgId);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($userId);
        $user->method('getRole')->willReturn($role);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn($orgId);
        $user->method('getUsername')->willReturn('u');
        return $user;
    }

    private function makeBooking(BookingStatus $status, string $orgId = 'org-1', string $tenantId = 't-1'): Booking&\PHPUnit\Framework\MockObject\MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn($orgId);
        $tenant = $this->createMock(User::class);
        $tenant->method('getId')->willReturn($tenantId);

        $booking = $this->createMock(Booking::class);
        $booking->method('getId')->willReturn('bk-1');
        $booking->method('getStatus')->willReturn($status);
        $booking->method('getOrganization')->willReturn($org);
        $booking->method('getOrganizationId')->willReturn($orgId);
        $booking->method('getTenantUserId')->willReturn($tenantId);
        $booking->method('getStartAt')->willReturn(new \DateTimeImmutable('+7 days'));
        $booking->method('getEndAt')->willReturn(new \DateTimeImmutable('+8 days'));
        $booking->method('getBaseAmount')->willReturn('100.00');
        $booking->method('getFinalAmount')->willReturn('100.00');
        $booking->method('getCurrency')->willReturn('USD');
        $booking->method('getBookedUnits')->willReturn(1);
        return $booking;
    }

    public function testCheckInHappyPath(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->atLeastOnce())->method('flush');

        $svc = $this->makeService($bookingRepo, null, null, $orgScope, $em);
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);
        $result = $svc->checkIn($manager, 'bk-1');
        $this->assertSame($booking, $result);
    }

    public function testCheckInWrongStatusThrows(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByIdAndOrg')->willReturn(
            $this->makeBooking(BookingStatus::ACTIVE),
        );

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeService($bookingRepo, null, null, $orgScope);
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);
        $this->expectException(\DomainException::class);
        $svc->checkIn($manager, 'bk-1');
    }

    public function testCompleteHappyPath(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $booking = $this->makeBooking(BookingStatus::ACTIVE);
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('persist');

        $svc = $this->makeService($bookingRepo, null, null, $orgScope, $em);
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);
        $result = $svc->complete($manager, 'bk-1');
        $this->assertSame($booking, $result);
    }

    public function testCompleteWrongStatusThrows(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByIdAndOrg')->willReturn(
            $this->makeBooking(BookingStatus::CONFIRMED),
        );

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeService($bookingRepo, null, null, $orgScope);
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);
        $this->expectException(\DomainException::class);
        $svc->complete($manager, 'bk-1');
    }

    public function testCancelByTenantSelfSucceeds(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $booking = $this->makeBooking(BookingStatus::CONFIRMED, 'org-1', 't-1');
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settings = $this->createMock(Settings::class);
        $settings->method('getCancellationFeePct')->willReturn('20.00');
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $em = $this->createMock(EntityManagerInterface::class);

        $svc = $this->makeService($bookingRepo, $settingsRepo, $orgTime, $orgScope, $em);
        $tenant = $this->makeUser(UserRole::TENANT, 't-1');
        $result = $svc->cancel($tenant, 'bk-1');
        $this->assertSame($booking, $result);
    }

    public function testCancelByTenantForOtherTenantDenied(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $booking = $this->makeBooking(BookingStatus::CONFIRMED, 'org-1', 't-owner');
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeService($bookingRepo, null, null, $orgScope);
        $other = $this->makeUser(UserRole::TENANT, 't-different');
        $this->expectException(AccessDeniedException::class);
        $svc->cancel($other, 'bk-1');
    }

    public function testCancelWrongStatusThrows(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByIdAndOrg')->willReturn(
            $this->makeBooking(BookingStatus::COMPLETED),
        );

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeService($bookingRepo, null, null, $orgScope);
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(\DomainException::class);
        $svc->cancel($admin, 'bk-1');
    }

    public function testMarkNoShowHappyPath(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settings = $this->createMock(Settings::class);
        $settings->method('getNoShowFeePct')->willReturn('25.00');
        $settings->method('getNoShowFirstDayRentEnabled')->willReturn(false);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $em = $this->createMock(EntityManagerInterface::class);

        $svc = $this->makeService($bookingRepo, $settingsRepo, null, $orgScope, $em);
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);
        $result = $svc->markNoShow($manager, 'bk-1');
        $this->assertSame($booking, $result);
    }

    public function testMarkNoShowWrongStatusThrows(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByIdAndOrg')->willReturn(
            $this->makeBooking(BookingStatus::COMPLETED),
        );

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeService($bookingRepo, null, null, $orgScope);
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);
        $this->expectException(\DomainException::class);
        $svc->markNoShow($manager, 'bk-1');
    }

    public function testGetBookingAsTenantReturnsOwnBooking(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $booking = $this->makeBooking(BookingStatus::CONFIRMED, 'org-1', 't-1');
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeService($bookingRepo, null, null, $orgScope);
        $tenant = $this->makeUser(UserRole::TENANT, 't-1');
        $result = $svc->getBooking($tenant, 'bk-1');
        $this->assertSame($booking, $result);
    }

    public function testGetBookingAsTenantForbiddenForOther(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $booking = $this->makeBooking(BookingStatus::CONFIRMED, 'org-1', 't-owner');
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeService($bookingRepo, null, null, $orgScope);
        $other = $this->makeUser(UserRole::TENANT, 't-other');
        $this->expectException(AccessDeniedException::class);
        $svc->getBooking($other, 'bk-1');
    }

    public function testListBookingsTenantFiltersByUserId(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->expects($this->once())
            ->method('findByOrg')
            ->willReturn([]);
        $bookingRepo->method('countByOrg')->willReturn(0);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeService($bookingRepo, null, null, $orgScope);
        $tenant = $this->makeUser(UserRole::TENANT, 't-1');
        $result = $svc->listBookings($tenant, [], 1, 25);
        $this->assertSame(1, $result['meta']['page']);
    }
}
