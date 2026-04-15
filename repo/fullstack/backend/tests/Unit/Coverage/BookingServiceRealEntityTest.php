<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\BookingHold;
use App\Entity\InventoryItem;
use App\Entity\InventoryPricing;
use App\Entity\Organization;
use App\Entity\Settings;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\BookingHoldStatus;
use App\Enum\BookingStatus;
use App\Enum\CapacityMode;
use App\Enum\RateType;
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

/**
 * BookingService state-transition coverage using REAL entities (not mocks).
 *
 * Previous tests used PHPUnit mocks for Booking, which meant markCheckedIn(),
 * markCompleted(), etc. were stubs and their state-transition code never ran.
 * This file instantiates real Booking, User, Organization, InventoryItem
 * entities so every transition method executes its actual logic — covering
 * previously-unreached lines in both BookingService and the Booking entity.
 */
class BookingServiceRealEntityTest extends TestCase
{
    private Organization $org;
    private User $admin;
    private User $tenant;
    private User $manager;
    private InventoryItem $item;

    protected function setUp(): void
    {
        $this->org = new Organization('org-1', 'ORG1', 'Org 1', 'USD');
        $this->admin = new User(
            'admin-1',
            $this->org,
            'admin1',
            'hashed',
            'Admin 1',
            UserRole::ADMINISTRATOR,
        );
        $this->manager = new User(
            'mgr-1',
            $this->org,
            'mgr1',
            'hashed',
            'Mgr 1',
            UserRole::PROPERTY_MANAGER,
        );
        $this->tenant = new User(
            'ten-1',
            $this->org,
            'ten1',
            'hashed',
            'Ten 1',
            UserRole::TENANT,
        );
        $this->item = new InventoryItem(
            'item-1',
            $this->org,
            'A-001',
            'Studio A',
            'studio',
            'Loc',
            CapacityMode::DISCRETE_UNITS,
            3,
            'UTC',
        );
    }

    private function makeBooking(BookingStatus $startStatus = BookingStatus::CONFIRMED): Booking
    {
        $booking = new Booking(
            'bk-1',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('+7 days'),
            new \DateTimeImmutable('+8 days'),
            1,
            'USD',
            '100.00',
            '100.00',
        );
        // Real entity starts in CONFIRMED by default; transition if needed.
        if ($startStatus !== BookingStatus::CONFIRMED) {
            $booking->transitionTo($startStatus);
        }
        return $booking;
    }

    private function makeService(
        ?BookingRepository $bookingRepo = null,
        ?SettingsRepository $settingsRepo = null,
        ?OrgTimeService $orgTime = null,
        ?BillingService $billing = null,
        ?BookingHoldService $holdSvc = null,
        ?PricingService $pricing = null,
        ?EntityManagerInterface $em = null,
    ): BookingService {
        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        return new BookingService(
            $bookingRepo ?? $this->createMock(BookingRepository::class),
            $this->createMock(BookingEventRepository::class),
            $settingsRepo ?? $this->createMock(SettingsRepository::class),
            $billing ?? $this->createMock(BillingService::class),
            $pricing ?? $this->createMock(PricingService::class),
            $holdSvc ?? $this->createMock(BookingHoldService::class),
            $this->createMock(LedgerService::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $orgScope,
            new RbacEnforcer(),
            $em ?? $this->createMock(EntityManagerInterface::class),
            $orgTime ?? $this->createMock(OrgTimeService::class),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // checkIn — confirmed → active
    // ═══════════════════════════════════════════════════════════════

    public function testCheckInTransitionsToActive(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->atLeastOnce())->method('flush');

        $svc = $this->makeService($repo, null, null, null, null, null, $em);
        $result = $svc->checkIn($this->manager, 'bk-1');

        $this->assertSame(BookingStatus::ACTIVE, $result->getStatus());
        $this->assertNotNull($result->getCheckedInAt());
    }

    // ═══════════════════════════════════════════════════════════════
    // complete — active → completed
    // ═══════════════════════════════════════════════════════════════

    public function testCompleteTransitionsToCompleted(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $booking->markCheckedIn(); // CONFIRMED → ACTIVE

        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $em = $this->createMock(EntityManagerInterface::class);

        $svc = $this->makeService($repo, null, null, null, null, null, $em);
        $result = $svc->complete($this->manager, 'bk-1');

        $this->assertSame(BookingStatus::COMPLETED, $result->getStatus());
        $this->assertNotNull($result->getCompletedAt());
    }

    // ═══════════════════════════════════════════════════════════════
    // cancel — with fee (< 24h until start)
    // ═══════════════════════════════════════════════════════════════

    public function testCancelWithFeeBelow24h(): void
    {
        // Booking starts in 2 hours — cancellation fee applies.
        $booking = new Booking(
            'bk-near',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('+2 hours'),
            new \DateTimeImmutable('+3 hours'),
            1,
            'USD',
            '200.00',
            '200.00',
        );
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $settings = $this->createMock(Settings::class);
        $settings->method('getCancellationFeePct')->willReturn('20.00');
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        $billing = $this->createMock(BillingService::class);
        $billing->expects($this->once())->method('issuePenaltyBill');

        $em = $this->createMock(EntityManagerInterface::class);

        $svc = $this->makeService($repo, $settingsRepo, $orgTime, $billing, null, null, $em);
        $result = $svc->cancel($this->admin, 'bk-near');

        $this->assertSame(BookingStatus::CANCELED, $result->getStatus());
        $this->assertNotNull($result->getCanceledAt());
        // 20% of 200.00 = 40.00
        $this->assertSame('40.00', $result->getCancellationFeeAmount());
    }

    public function testCancelWithoutFeeFarFuture(): void
    {
        // Booking starts in 10 days — no fee.
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $settings = $this->createMock(Settings::class);
        $settings->method('getCancellationFeePct')->willReturn('20.00');
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        $billing = $this->createMock(BillingService::class);
        $billing->expects($this->never())->method('issuePenaltyBill');

        $svc = $this->makeService($repo, $settingsRepo, $orgTime, $billing);
        $result = $svc->cancel($this->admin, 'bk-1');

        $this->assertSame(BookingStatus::CANCELED, $result->getStatus());
        $this->assertSame('0.00', $result->getCancellationFeeAmount());
    }

    public function testCancelSettingsNullUsesDefaults(): void
    {
        $booking = new Booking(
            'bk-default',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('+2 hours'),
            new \DateTimeImmutable('+3 hours'),
            1,
            'USD',
            '100.00',
            '100.00',
        );
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        $billing = $this->createMock(BillingService::class);
        $billing->expects($this->once())->method('issuePenaltyBill');

        $svc = $this->makeService($repo, $settingsRepo, $orgTime, $billing);
        $result = $svc->cancel($this->admin, 'bk-default');

        // Default 20% of 100 = 20.00
        $this->assertSame('20.00', $result->getCancellationFeeAmount());
    }

    public function testCancelFromActiveSucceeds(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $booking->markCheckedIn();

        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $settings = $this->createMock(Settings::class);
        $settings->method('getCancellationFeePct')->willReturn('10.00');
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        $svc = $this->makeService($repo, $settingsRepo, $orgTime);
        $result = $svc->cancel($this->admin, 'bk-1');

        $this->assertSame(BookingStatus::CANCELED, $result->getStatus());
    }

    // ═══════════════════════════════════════════════════════════════
    // markNoShow — with grace period + penalty calc
    // ═══════════════════════════════════════════════════════════════

    public function testMarkNoShowFullPenaltyWithFirstDayRent(): void
    {
        $booking = new Booking(
            'bk-ns',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('-1 hour'),   // started 1h ago
            new \DateTimeImmutable('+23 hours'),
            1,
            'USD',
            '100.00',
            '100.00',
        );
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $settings = $this->createMock(Settings::class);
        $settings->method('getNoShowFeePct')->willReturn('50.00');
        $settings->method('getNoShowGracePeriodMinutes')->willReturn(30);
        $settings->method('getNoShowFirstDayRentEnabled')->willReturn(true);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        // Pricing: $100/day
        $pricing = $this->createMock(InventoryPricing::class);
        $pricing->method('getRateType')->willReturn(RateType::DAILY);
        $pricing->method('getAmount')->willReturn('100.00');

        $pricingSvc = $this->createMock(PricingService::class);
        $pricingSvc->method('getActivePricing')->willReturn($pricing);

        $billing = $this->createMock(BillingService::class);
        $billing->expects($this->once())->method('issuePenaltyBill');

        $svc = $this->makeService($repo, $settingsRepo, $orgTime, $billing, null, $pricingSvc);
        $result = $svc->markNoShow($this->manager, 'bk-ns');

        $this->assertSame(BookingStatus::NO_SHOW, $result->getStatus());
        // 50% of 100 = 50, + 100 day rent = 150
        $this->assertSame('150.00', $result->getNoShowPenaltyAmount());
    }

    public function testMarkNoShowHourlyRateFirstDayRent(): void
    {
        $booking = new Booking(
            'bk-hr',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('-2 hours'),
            new \DateTimeImmutable('+1 hour'),
            1,
            'USD',
            '60.00',
            '60.00',
        );
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $settings = $this->createMock(Settings::class);
        $settings->method('getNoShowFeePct')->willReturn('25.00');
        $settings->method('getNoShowGracePeriodMinutes')->willReturn(15);
        $settings->method('getNoShowFirstDayRentEnabled')->willReturn(true);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        // Pricing: $5/hour → $120/day
        $pricing = $this->createMock(InventoryPricing::class);
        $pricing->method('getRateType')->willReturn(RateType::HOURLY);
        $pricing->method('getAmount')->willReturn('5.00');

        $pricingSvc = $this->createMock(PricingService::class);
        $pricingSvc->method('getActivePricing')->willReturn($pricing);

        $svc = $this->makeService($repo, $settingsRepo, $orgTime, null, null, $pricingSvc);
        $result = $svc->markNoShow($this->manager, 'bk-hr');

        // 25% of 60 = 15, + 120 day rent = 135
        $this->assertSame('135.00', $result->getNoShowPenaltyAmount());
    }

    public function testMarkNoShowMonthlyRateFirstDayRent(): void
    {
        $booking = new Booking(
            'bk-mo',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('-2 hours'),
            new \DateTimeImmutable('+30 days'),
            1,
            'USD',
            '3000.00',
            '3000.00',
        );
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $settings = $this->createMock(Settings::class);
        $settings->method('getNoShowFeePct')->willReturn('10.00');
        $settings->method('getNoShowGracePeriodMinutes')->willReturn(15);
        $settings->method('getNoShowFirstDayRentEnabled')->willReturn(true);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        // Pricing: $3000/month → $100/day
        $pricing = $this->createMock(InventoryPricing::class);
        $pricing->method('getRateType')->willReturn(RateType::MONTHLY);
        $pricing->method('getAmount')->willReturn('3000.00');

        $pricingSvc = $this->createMock(PricingService::class);
        $pricingSvc->method('getActivePricing')->willReturn($pricing);

        $svc = $this->makeService($repo, $settingsRepo, $orgTime, null, null, $pricingSvc);
        $result = $svc->markNoShow($this->manager, 'bk-mo');

        // 10% of 3000 = 300, + 100 day rent = 400
        $this->assertSame('400.00', $result->getNoShowPenaltyAmount());
    }

    public function testMarkNoShowFlatRateFirstDayRent(): void
    {
        $booking = new Booking(
            'bk-flat',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('-2 hours'),
            new \DateTimeImmutable('+1 day'),
            2,
            'USD',
            '200.00',
            '200.00',
        );
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $settings = $this->createMock(Settings::class);
        $settings->method('getNoShowFeePct')->willReturn('50.00');
        $settings->method('getNoShowGracePeriodMinutes')->willReturn(15);
        $settings->method('getNoShowFirstDayRentEnabled')->willReturn(true);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        $pricing = $this->createMock(InventoryPricing::class);
        $pricing->method('getRateType')->willReturn(RateType::FLAT);
        $pricing->method('getAmount')->willReturn('50.00');

        $pricingSvc = $this->createMock(PricingService::class);
        $pricingSvc->method('getActivePricing')->willReturn($pricing);

        $svc = $this->makeService($repo, $settingsRepo, $orgTime, null, null, $pricingSvc);
        $result = $svc->markNoShow($this->manager, 'bk-flat');

        // 50% of 200 = 100, + (50 * 2 units) = 100 → 200 total
        $this->assertSame('200.00', $result->getNoShowPenaltyAmount());
    }

    public function testMarkNoShowWithoutFirstDayRent(): void
    {
        $booking = new Booking(
            'bk-no-rent',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('+23 hours'),
            1,
            'USD',
            '100.00',
            '100.00',
        );
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $settings = $this->createMock(Settings::class);
        $settings->method('getNoShowFeePct')->willReturn('30.00');
        $settings->method('getNoShowGracePeriodMinutes')->willReturn(15);
        $settings->method('getNoShowFirstDayRentEnabled')->willReturn(false);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        $svc = $this->makeService($repo, $settingsRepo, $orgTime);
        $result = $svc->markNoShow($this->manager, 'bk-no-rent');

        // 30% of 100 = 30, no first-day rent
        $this->assertSame('30.00', $result->getNoShowPenaltyAmount());
    }

    public function testMarkNoShowGracePeriodNotElapsedThrows(): void
    {
        $booking = new Booking(
            'bk-grace',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('+5 minutes'),  // starts in 5 minutes
            new \DateTimeImmutable('+1 hour'),
            1,
            'USD',
            '100.00',
            '100.00',
        );
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $settings = $this->createMock(Settings::class);
        $settings->method('getNoShowGracePeriodMinutes')->willReturn(30);
        $settings->method('getNoShowFeePct')->willReturn('50.00');
        $settings->method('getNoShowFirstDayRentEnabled')->willReturn(false);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        $svc = $this->makeService($repo, $settingsRepo, $orgTime);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Grace period has not yet elapsed');
        $svc->markNoShow($this->manager, 'bk-grace');
    }

    public function testMarkNoShowRejectedWhenAlreadyCheckedIn(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $booking->markCheckedIn();  // active + checkedInAt set

        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $svc = $this->makeService($repo);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot mark as no-show: guest has already checked in');
        $svc->markNoShow($this->manager, 'bk-1');
    }

    public function testMarkNoShowRejectsCompletedStatus(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $booking->markCheckedIn();
        $booking->markCompleted();

        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $svc = $this->makeService($repo);
        $this->expectException(\DomainException::class);
        $svc->markNoShow($this->manager, 'bk-1');
    }

    // ═══════════════════════════════════════════════════════════════
    // reschedule
    // ═══════════════════════════════════════════════════════════════

    public function testRescheduleByTenantSucceeds(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $newHold = new BookingHold(
            'h-new',
            $this->org,
            $this->item,
            $this->tenant,
            'req-1',
            1,
            new \DateTimeImmutable('+30 days'),
            new \DateTimeImmutable('+31 days'),
            new \DateTimeImmutable('+15 minutes'),
        );

        $holdSvc = $this->createMock(BookingHoldService::class);
        $holdSvc->method('getHold')->willReturn($newHold);

        $em = $this->createMock(EntityManagerInterface::class);

        $svc = $this->makeService($repo, null, null, null, $holdSvc, null, $em);
        $result = $svc->reschedule($this->tenant, 'bk-1', 'h-new');

        $this->assertSame(BookingStatus::CONFIRMED, $result->getStatus());
        $this->assertSame($newHold->getStartAt(), $result->getStartAt());
        $this->assertSame($newHold->getEndAt(), $result->getEndAt());
    }

    public function testRescheduleRejectsOtherTenantsBooking(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $other = new User(
            'ten-2',
            $this->org,
            'ten2',
            'hashed',
            'Ten 2',
            UserRole::TENANT,
        );

        $svc = $this->makeService($repo);
        $this->expectException(AccessDeniedException::class);
        $svc->reschedule($other, 'bk-1', 'h-new');
    }

    public function testRescheduleRejectsCompletedBooking(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $booking->markCheckedIn();
        $booking->markCompleted();

        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $svc = $this->makeService($repo);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('CONFIRMED to reschedule');
        $svc->reschedule($this->admin, 'bk-1', 'h-new');
    }

    public function testRescheduleRejectsNonActiveNewHold(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $newHold = new BookingHold(
            'h-new',
            $this->org,
            $this->item,
            $this->tenant,
            'req-1',
            1,
            new \DateTimeImmutable('+30 days'),
            new \DateTimeImmutable('+31 days'),
            new \DateTimeImmutable('+15 minutes'),
        );
        $newHold->transitionTo(BookingHoldStatus::RELEASED);

        $holdSvc = $this->createMock(BookingHoldService::class);
        $holdSvc->method('getHold')->willReturn($newHold);

        $svc = $this->makeService($repo, null, null, null, $holdSvc);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ACTIVE');
        $svc->reschedule($this->admin, 'bk-1', 'h-new');
    }

    public function testRescheduleRejectsDifferentTenantHold(): void
    {
        $booking = $this->makeBooking(BookingStatus::CONFIRMED);
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($booking);

        $otherTenant = new User(
            'ten-2',
            $this->org,
            'ten2',
            'hashed',
            'Ten 2',
            UserRole::TENANT,
        );

        $newHold = new BookingHold(
            'h-new',
            $this->org,
            $this->item,
            $otherTenant,
            'req-1',
            1,
            new \DateTimeImmutable('+30 days'),
            new \DateTimeImmutable('+31 days'),
            new \DateTimeImmutable('+15 minutes'),
        );

        $holdSvc = $this->createMock(BookingHoldService::class);
        $holdSvc->method('getHold')->willReturn($newHold);

        $svc = $this->makeService($repo, null, null, null, $holdSvc);
        $this->expectException(AccessDeniedException::class);
        $svc->reschedule($this->admin, 'bk-1', 'h-new');
    }

    // ═══════════════════════════════════════════════════════════════
    // evaluateNoShows — scheduler entry point
    // ═══════════════════════════════════════════════════════════════

    public function testEvaluateNoShowsEmptyReturnsZero(): void
    {
        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findNoShowCandidates')->willReturn([]);

        $svc = $this->makeService($repo);
        $this->assertSame(0, $svc->evaluateNoShows());
    }

    public function testEvaluateNoShowsProcessesCandidates(): void
    {
        // Candidate: past-start booking, grace elapsed, not checked-in
        $booking = new Booking(
            'bk-cand',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('-2 hours'),
            new \DateTimeImmutable('+22 hours'),
            1,
            'USD',
            '100.00',
            '100.00',
        );

        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findNoShowCandidates')->willReturn([$booking]);

        $settings = $this->createMock(Settings::class);
        $settings->method('getNoShowGracePeriodMinutes')->willReturn(30);
        $settings->method('getNoShowFeePct')->willReturn('50.00');
        $settings->method('getNoShowFirstDayRentEnabled')->willReturn(false);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(function ($cb) { return $cb(); });

        $svc = $this->makeService($repo, $settingsRepo, $orgTime, null, null, null, $em);
        $this->assertSame(1, $svc->evaluateNoShows());
        $this->assertSame(BookingStatus::NO_SHOW, $booking->getStatus());
    }

    public function testEvaluateNoShowsSkipsWithinGracePeriod(): void
    {
        $booking = new Booking(
            'bk-grace',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('+5 minutes'),
            new \DateTimeImmutable('+1 hour'),
            1,
            'USD',
            '100.00',
            '100.00',
        );

        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findNoShowCandidates')->willReturn([$booking]);

        $settings = $this->createMock(Settings::class);
        $settings->method('getNoShowGracePeriodMinutes')->willReturn(30);
        $settings->method('getNoShowFeePct')->willReturn('50.00');
        $settings->method('getNoShowFirstDayRentEnabled')->willReturn(false);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(function ($cb) { return $cb(); });

        $svc = $this->makeService($repo, $settingsRepo, $orgTime, null, null, null, $em);
        // Grace period not elapsed yet → should be 0 processed
        $this->assertSame(0, $svc->evaluateNoShows());
        // Booking remains CONFIRMED
        $this->assertSame(BookingStatus::CONFIRMED, $booking->getStatus());
    }

    public function testEvaluateNoShowsSwallowsPerBookingFailures(): void
    {
        $booking = new Booking(
            'bk-ne',
            $this->org,
            $this->item,
            $this->tenant,
            null,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('+23 hours'),
            1,
            'USD',
            '100.00',
            '100.00',
        );

        $repo = $this->createMock(BookingRepository::class);
        $repo->method('findNoShowCandidates')->willReturn([$booking]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException(new \RuntimeException('simulated'));

        $svc = $this->makeService($repo, null, null, null, null, null, $em);
        // Failure inside the loop must not propagate; count stays 0
        $this->assertSame(0, $svc->evaluateNoShows());
    }
}
