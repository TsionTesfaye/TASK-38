<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Booking;
use App\Entity\Organization;
use App\Entity\Settings;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\BillType;
use App\Repository\BillRepository;
use App\Repository\PaymentRepository;
use App\Repository\RefundRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BillingService;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\OrgTimeService;
use App\Service\PricingService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Exercises BillingService::generateRecurringBills() through the real service
 * with mocked OrgTimeService to prove:
 *   - billing triggers at org-local 9:00 on billing day
 *   - different timezones are handled independently
 *   - billing is skipped before the billing window
 *   - duplicate bills are not created
 *   - no org is skipped when conditions are met
 */
class RecurringBillingTimezoneTest extends TestCase
{
    private BillRepository&MockObject $billRepo;
    private SettingsRepository&MockObject $settingsRepo;
    private OrgTimeService&MockObject $timeService;
    private EntityManagerInterface&MockObject $em;
    private BillingService $service;

    /** @var array<string, Settings&MockObject> */
    private array $settingsByOrg = [];

    protected function setUp(): void
    {
        $this->billRepo = $this->createMock(BillRepository::class);
        $this->settingsRepo = $this->createMock(SettingsRepository::class);
        $this->timeService = $this->createMock(OrgTimeService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        // By default, no existing bill (no duplicate).
        $this->billRepo->method('findByBookingAndPeriod')->willReturn(null);

        // Settings lookup dispatches by org ID.
        $this->settingsRepo->method('findByOrganizationId')
            ->willReturnCallback(fn (string $orgId) => $this->settingsByOrg[$orgId] ?? null);

        $this->service = new BillingService(
            $this->billRepo,
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->settingsRepo,
            $this->createMock(LedgerService::class),
            $this->createMock(PricingService::class),
            $this->createMock(OrganizationScope::class),
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->timeService,
            $this->em,
        );
    }

    private function makeSettings(string $timezone, int $billingDay = 1, int $billingHour = 9): Settings&MockObject
    {
        $s = $this->createMock(Settings::class);
        $s->method('getTimezone')->willReturn($timezone);
        $s->method('getRecurringBillDay')->willReturn($billingDay);
        $s->method('getRecurringBillHour')->willReturn($billingHour);
        return $s;
    }

    private function makeBooking(string $id, string $orgId): Booking&MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn($orgId);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('tenant-1');

        $b = $this->createMock(Booking::class);
        $b->method('getId')->willReturn($id);
        $b->method('getOrganizationId')->willReturn($orgId);
        $b->method('getOrganization')->willReturn($org);
        $b->method('getTenantUser')->willReturn($user);
        $b->method('getTenantUserId')->willReturn('tenant-1');
        $b->method('getInventoryItemId')->willReturn('item-1');
        $b->method('getBookedUnits')->willReturn(1);
        $b->method('getCurrency')->willReturn('USD');
        $b->method('getStatus')->willReturn(BookingStatus::ACTIVE);
        return $b;
    }

    /**
     * Wire the EntityManager to return the given bookings from the ACTIVE query.
     * @param Booking[] $bookings
     */
    private function stubActiveBookings(array $bookings): void
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn($bookings);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('getRepository')->willReturn($repo);
        // wrapInTransaction just executes the callback.
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());
    }

    // ═══════════════════════════════════════════════════════════════
    // A. TIMEZONE-CORRECT TRIGGERING
    // ═══════════════════════════════════════════════════════════════

    /**
     * Tokyo (UTC+9): when UTC is Jan 1 00:00, Tokyo is Jan 1 09:00.
     * Billing should trigger for the Tokyo org.
     */
    public function testTokyoOrgTriggersAtLocalDay1Hour9(): void
    {
        $this->settingsByOrg['org-tokyo'] = $this->makeSettings('Asia/Tokyo');

        // OrgTimeService returns Jan 1 at 09:00 Tokyo time.
        $this->timeService->method('now')
            ->willReturn(new \DateTimeImmutable('2026-01-01 09:00:00', new \DateTimeZone('Asia/Tokyo')));
        $this->timeService->method('getCurrentPeriod')->willReturn('2026-01');

        $booking = $this->makeBooking('b-tokyo', 'org-tokyo');
        $this->stubActiveBookings([$booking]);

        $count = $this->service->generateRecurringBills();

        $this->assertSame(1, $count, 'Tokyo org at local 09:00 on day 1 must generate bill');
    }

    /**
     * New York (UTC-5): when UTC is Jan 1 12:00, NYC is Jan 1 07:00.
     * Billing should NOT trigger — hour 7 < billing hour 9.
     */
    public function testNycOrgSkippedBeforeLocalBillingHour(): void
    {
        $this->settingsByOrg['org-nyc'] = $this->makeSettings('America/New_York');

        $this->timeService->method('now')
            ->willReturn(new \DateTimeImmutable('2026-01-01 07:00:00', new \DateTimeZone('America/New_York')));

        $booking = $this->makeBooking('b-nyc', 'org-nyc');
        $this->stubActiveBookings([$booking]);

        $count = $this->service->generateRecurringBills();

        $this->assertSame(0, $count, 'NYC org at local 07:00 must be skipped (before billing hour)');
    }

    /**
     * UTC org on day 2 — billing day is 1 (default).
     * Gate should skip: day 2 !== 1.
     */
    public function testSkippedOnWrongDay(): void
    {
        $this->settingsByOrg['org-utc'] = $this->makeSettings('UTC');

        $this->timeService->method('now')
            ->willReturn(new \DateTimeImmutable('2026-01-02 10:00:00', new \DateTimeZone('UTC')));

        $booking = $this->makeBooking('b-utc', 'org-utc');
        $this->stubActiveBookings([$booking]);

        $count = $this->service->generateRecurringBills();

        $this->assertSame(0, $count, 'Day 2 must be skipped when billing day is 1');
    }

    // ═══════════════════════════════════════════════════════════════
    // B. MULTI-ORG: NO ORG SKIPPED
    // ═══════════════════════════════════════════════════════════════

    /**
     * Two orgs in different timezones. Both are on billing day at billing hour.
     * Both must generate bills.
     */
    public function testMultipleOrgsAllTriggerWhenInWindow(): void
    {
        $this->settingsByOrg['org-tokyo'] = $this->makeSettings('Asia/Tokyo');
        $this->settingsByOrg['org-london'] = $this->makeSettings('Europe/London');

        // Both return local Jan 1 at 10:00 in their respective timezones.
        $this->timeService->method('now')
            ->willReturnCallback(function (string $orgId) {
                $tz = $orgId === 'org-tokyo' ? 'Asia/Tokyo' : 'Europe/London';
                return new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone($tz));
            });
        $this->timeService->method('getCurrentPeriod')->willReturn('2026-01');

        $bookings = [
            $this->makeBooking('b-tokyo', 'org-tokyo'),
            $this->makeBooking('b-london', 'org-london'),
        ];
        $this->stubActiveBookings($bookings);

        $count = $this->service->generateRecurringBills();

        $this->assertSame(2, $count, 'Both orgs must generate bills when in window');
    }

    /**
     * Two orgs, one in window, one not. Only the in-window org bills.
     */
    public function testMixedTimezoneOnlyEligibleOrgBills(): void
    {
        $this->settingsByOrg['org-tokyo'] = $this->makeSettings('Asia/Tokyo');
        $this->settingsByOrg['org-nyc'] = $this->makeSettings('America/New_York');

        $this->timeService->method('now')
            ->willReturnCallback(function (string $orgId) {
                if ($orgId === 'org-tokyo') {
                    // Tokyo: Jan 1 at 09:30 → in window
                    return new \DateTimeImmutable('2026-01-01 09:30:00', new \DateTimeZone('Asia/Tokyo'));
                }
                // NYC: Jan 1 at 07:00 → NOT in window (before hour 9)
                return new \DateTimeImmutable('2026-01-01 07:00:00', new \DateTimeZone('America/New_York'));
            });
        $this->timeService->method('getCurrentPeriod')->willReturn('2026-01');

        $bookings = [
            $this->makeBooking('b-tokyo', 'org-tokyo'),
            $this->makeBooking('b-nyc', 'org-nyc'),
        ];
        $this->stubActiveBookings($bookings);

        $count = $this->service->generateRecurringBills();

        $this->assertSame(1, $count, 'Only Tokyo org should bill; NYC is before billing hour');
    }

    // ═══════════════════════════════════════════════════════════════
    // C. DUPLICATE PREVENTION
    // ═══════════════════════════════════════════════════════════════

    public function testNoDuplicateBillCreatedForSamePeriod(): void
    {
        $this->settingsByOrg['org-utc'] = $this->makeSettings('UTC');

        $this->timeService->method('now')
            ->willReturn(new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC')));
        $this->timeService->method('getCurrentPeriod')->willReturn('2026-01');

        // Simulate: a bill already exists for this booking+period.
        $existingBill = $this->createMock(\App\Entity\Bill::class);
        $this->billRepo->expects($this->any())->method('findByBookingAndPeriod')
            ->willReturn($existingBill);

        $booking = $this->makeBooking('b-utc', 'org-utc');
        $this->stubActiveBookings([$booking]);

        $count = $this->service->generateRecurringBills();

        $this->assertSame(0, $count, 'Must not create duplicate bill for same period');
    }

    // ═══════════════════════════════════════════════════════════════
    // D. CUSTOM BILLING DAY / HOUR
    // ═══════════════════════════════════════════════════════════════

    public function testCustomBillingDay15Hour14Triggers(): void
    {
        $this->settingsByOrg['org-custom'] = $this->makeSettings('UTC', billingDay: 15, billingHour: 14);

        $this->timeService->method('now')
            ->willReturn(new \DateTimeImmutable('2026-01-15 14:00:00', new \DateTimeZone('UTC')));
        $this->timeService->method('getCurrentPeriod')->willReturn('2026-01');

        $booking = $this->makeBooking('b-custom', 'org-custom');
        $this->stubActiveBookings([$booking]);

        $count = $this->service->generateRecurringBills();

        $this->assertSame(1, $count, 'Custom day 15 at hour 14 must trigger');
    }

    public function testCustomBillingDaySkippedOnWrongDay(): void
    {
        $this->settingsByOrg['org-custom'] = $this->makeSettings('UTC', billingDay: 15, billingHour: 14);

        $this->timeService->method('now')
            ->willReturn(new \DateTimeImmutable('2026-01-14 14:00:00', new \DateTimeZone('UTC')));

        $booking = $this->makeBooking('b-custom', 'org-custom');
        $this->stubActiveBookings([$booking]);

        $count = $this->service->generateRecurringBills();

        $this->assertSame(0, $count, 'Day 14 must be skipped when billing day is 15');
    }

    // ═══════════════════════════════════════════════════════════════
    // E. DEFAULT SETTINGS (null settings → day=1, hour=9, UTC)
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultsUsedWhenNoSettings(): void
    {
        // org-nosettings has no Settings row → defaults: day=1, hour=9
        // (settingsRepo returns null for this org)

        $this->timeService->method('now')
            ->willReturn(new \DateTimeImmutable('2026-01-01 09:00:00', new \DateTimeZone('UTC')));
        $this->timeService->method('getCurrentPeriod')->willReturn('2026-01');

        $booking = $this->makeBooking('b-none', 'org-nosettings');
        $this->stubActiveBookings([$booking]);

        $count = $this->service->generateRecurringBills();

        $this->assertSame(1, $count, 'Default day=1, hour=9 must trigger on Jan 1 at 09:00 UTC');
    }
}
