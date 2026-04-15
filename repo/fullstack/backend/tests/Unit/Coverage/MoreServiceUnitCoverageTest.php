<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\LedgerEntryType;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Repository\LedgerEntryRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\NotificationRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\OrgTimeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MoreServiceUnitCoverageTest extends TestCase
{
    private function makeUser(UserRole $role, string $orgId = 'org-1', string $userId = 'user-1'): User&MockObject
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

    // ═══════════════════════════════════════════════════════════════
    // LedgerService
    // ═══════════════════════════════════════════════════════════════

    public function testLedgerServiceCreateEntry(): void
    {
        $org = $this->createMock(Organization::class);
        $booking = $this->createMock(Booking::class);
        $bill = $this->createMock(Bill::class);
        $payment = $this->createMock(\App\Entity\Payment::class);
        $refund = $this->createMock(\App\Entity\Refund::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getReference')->willReturnCallback(function ($cls) use ($org, $booking, $bill, $payment, $refund) {
            return match ($cls) {
                Organization::class => $org,
                Booking::class => $booking,
                Bill::class => $bill,
                \App\Entity\Payment::class => $payment,
                \App\Entity\Refund::class => $refund,
                default => null,
            };
        });

        $em->expects($this->once())->method('persist');

        $ledger = new LedgerService(
            $this->createMock(LedgerEntryRepository::class),
            $em,
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
        );

        // Creates entry with all FK references
        $entry = $ledger->createEntry(
            'org-1', LedgerEntryType::PAYMENT_RECEIVED, '100.00', 'USD',
            'booking-1', 'bill-1', 'pay-1', 'refund-1', ['note' => 'test'],
        );
        $this->assertInstanceOf(\App\Entity\LedgerEntry::class, $entry);
    }

    public function testLedgerServiceCreateEntryWithOptionalNulls(): void
    {
        $org = $this->createMock(Organization::class);
        $booking = $this->createMock(Booking::class);
        $bill = $this->createMock(Bill::class);
        $payment = $this->createMock(\App\Entity\Payment::class);
        $refund = $this->createMock(\App\Entity\Refund::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getReference')->willReturnCallback(function ($cls) use ($org, $booking, $bill, $payment, $refund) {
            return match ($cls) {
                Organization::class => $org,
                Booking::class => $booking,
                Bill::class => $bill,
                \App\Entity\Payment::class => $payment,
                \App\Entity\Refund::class => $refund,
                default => null,
            };
        });

        $ledger = new LedgerService(
            $this->createMock(LedgerEntryRepository::class),
            $em,
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
        );

        // Creates entry without optional FK references
        $entry = $ledger->createEntry(
            'org-1', LedgerEntryType::BILL_ISSUED, '50.00', 'USD',
        );
        $this->assertInstanceOf(\App\Entity\LedgerEntry::class, $entry);
    }

    public function testLedgerServiceGetEntriesForBillNotFound(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $ledger = new LedgerService(
            $this->createMock(LedgerEntryRepository::class),
            $em, $orgScope, new RbacEnforcer(),
        );

        $user = $this->makeUser(UserRole::FINANCE_CLERK);
        $this->expectException(EntityNotFoundException::class);
        $ledger->getEntriesForBill($user, 'nonexistent-bill');
    }

    public function testLedgerServiceGetEntriesForBookingNotFound(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $ledger = new LedgerService(
            $this->createMock(LedgerEntryRepository::class),
            $em, $orgScope, new RbacEnforcer(),
        );

        $user = $this->makeUser(UserRole::FINANCE_CLERK);
        $this->expectException(EntityNotFoundException::class);
        $ledger->getEntriesForBooking($user, 'nonexistent-booking');
    }

    public function testLedgerServiceAccessDeniedForTenant(): void
    {
        $ledger = new LedgerService(
            $this->createMock(LedgerEntryRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
        );

        $tenant = $this->makeUser(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $ledger->getEntriesForBill($tenant, 'bill-1');
    }

    public function testLedgerServiceListEntries(): void
    {
        $repo = $this->createMock(LedgerEntryRepository::class);
        $repo->method('findByOrg')->willReturn([]);
        $repo->method('countByOrg')->willReturn(0);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $ledger = new LedgerService(
            $repo,
            $this->createMock(EntityManagerInterface::class),
            $orgScope, new RbacEnforcer(),
        );

        $user = $this->makeUser(UserRole::FINANCE_CLERK);
        $result = $ledger->listEntries($user, [], 1, 25);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // NotificationService — additional branches
    // ═══════════════════════════════════════════════════════════════

    public function testNotificationServiceIsInDndWindowCoversAllBranches(): void
    {
        $service = new NotificationService(
            $this->createMock(NotificationRepository::class),
            $this->createMock(NotificationPreferenceRepository::class),
            $this->createMock(SettingsRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(OrganizationScope::class),
            $this->createMock(OrgTimeService::class),
        );

        // Same start/end → DND disabled
        $this->assertFalse($service->isInDndWindow('12:00', '12:00', '12:00'));

        // Midnight-crossing: start > end
        $this->assertTrue($service->isInDndWindow('21:00', '08:00', '22:00'));
        $this->assertTrue($service->isInDndWindow('21:00', '08:00', '07:00'));
        $this->assertFalse($service->isInDndWindow('21:00', '08:00', '10:00'));

        // Same-day: start < end
        $this->assertTrue($service->isInDndWindow('13:00', '15:00', '14:00'));
        $this->assertFalse($service->isInDndWindow('13:00', '15:00', '16:00'));
        $this->assertFalse($service->isInDndWindow('13:00', '15:00', '10:00'));
    }

    // ═══════════════════════════════════════════════════════════════
    // OrgTimeService — all methods
    // ═══════════════════════════════════════════════════════════════

    public function testOrgTimeServiceAllMethods(): void
    {
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

        $s = new OrgTimeService($settingsRepo);

        $this->assertSame('UTC', $s->getTimezone('org-x')->getName());
        $this->assertInstanceOf(\DateTimeImmutable::class, $s->now('org-x'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $s->getCurrentPeriod('org-x'));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $s->getCurrentLocalTime('org-x'));

        $future = new \DateTimeImmutable('+3 hours');
        $hours = $s->hoursUntil($future, 'org-x');
        $this->assertGreaterThan(2.9, $hours);
    }
}
