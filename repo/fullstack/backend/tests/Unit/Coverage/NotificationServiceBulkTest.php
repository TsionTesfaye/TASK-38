<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\Organization;
use App\Entity\Settings;
use App\Entity\User;
use App\Enum\NotificationStatus;
use App\Enum\UserRole;
use App\Exception\EntityNotFoundException;
use App\Exception\InvalidStateTransitionException;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\NotificationRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Service\NotificationService;
use App\Service\OrgTimeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * NotificationService unit tests covering createNotification with DND logic,
 * deliverPendingNotifications scheduler, markRead transitions,
 * updatePreference, and getPreferences.
 */
class NotificationServiceBulkTest extends TestCase
{
    private Organization $org;
    private User $tenant;

    protected function setUp(): void
    {
        $this->org = new Organization('org-n', 'N', 'N Org', 'USD');
        $this->tenant = new User('u-n', $this->org, 'n', 'h', 'N', UserRole::TENANT);
    }

    private function makeService(
        ?NotificationRepository $notifRepo = null,
        ?NotificationPreferenceRepository $prefRepo = null,
        ?SettingsRepository $settingsRepo = null,
        ?EntityManagerInterface $em = null,
        ?OrgTimeService $orgTime = null,
    ): NotificationService {
        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-n');

        return new NotificationService(
            $notifRepo ?? $this->createMock(NotificationRepository::class),
            $prefRepo ?? $this->createMock(NotificationPreferenceRepository::class),
            $settingsRepo ?? $this->createMock(SettingsRepository::class),
            $em ?? $this->createMock(EntityManagerInterface::class),
            $orgScope,
            $orgTime ?? $this->createMock(OrgTimeService::class),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // createNotification
    // ═══════════════════════════════════════════════════════════════

    public function testCreateNotificationUserNotFoundThrows(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $svc = $this->makeService(null, null, null, $em);
        $this->expectException(EntityNotFoundException::class);
        $svc->createNotification('org-n', 'missing-user', 'x', 'T', 'B');
    }

    public function testCreateNotificationUserWrongOrgThrows(): void
    {
        $otherOrg = new Organization('org-other', 'O', 'Other', 'USD');
        $otherUser = new User('u-other', $otherOrg, 'u', 'h', 'U', UserRole::TENANT);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($otherUser);

        $svc = $this->makeService(null, null, null, $em);
        $this->expectException(EntityNotFoundException::class);
        $svc->createNotification('org-n', 'u-other', 'x', 'T', 'B');
    }

    public function testCreateNotificationDisabledPreferenceReturnsQueued(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($this->tenant);

        $pref = $this->createMock(NotificationPreference::class);
        $pref->method('isEnabled')->willReturn(false);

        $prefRepo = $this->createMock(NotificationPreferenceRepository::class);
        $prefRepo->method('findByUserAndEvent')->willReturn($pref);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        $svc = $this->makeService(null, $prefRepo, null, $em, $orgTime);
        $r = $svc->createNotification('org-n', 'u-n', 'x', 'T', 'B');
        $this->assertInstanceOf(Notification::class, $r);
        // When disabled, the method returns without persisting
        $this->assertSame(NotificationStatus::PENDING, $r->getStatus());
    }

    public function testCreateNotificationUsesSettingsTemplate(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($this->tenant);

        $settings = $this->createMock(Settings::class);
        $settings->method('getNotificationTemplate')->willReturn('Templated body');

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable('2026-06-15 14:00:00'));
        $orgTime->method('getCurrentLocalTime')->willReturn('14:00');  // outside DND

        $prefRepo = $this->createMock(NotificationPreferenceRepository::class);
        $prefRepo->method('findByUserAndEvent')->willReturn(null);

        $svc = $this->makeService(null, $prefRepo, $settingsRepo, $em, $orgTime);
        $r = $svc->createNotification('org-n', 'u-n', 'x', 'T', 'B');
        $this->assertSame('Templated body', $r->getBody());
        // Outside DND → delivered immediately
        $this->assertSame(NotificationStatus::DELIVERED, $r->getStatus());
    }

    public function testCreateNotificationWithinDndScheduledForEnd(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($this->tenant);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable('2026-06-15 23:00:00'));
        $orgTime->method('getCurrentLocalTime')->willReturn('23:00');  // inside default 21-08 DND

        $prefRepo = $this->createMock(NotificationPreferenceRepository::class);
        $prefRepo->method('findByUserAndEvent')->willReturn(null);

        $svc = $this->makeService(null, $prefRepo, null, $em, $orgTime);
        $r = $svc->createNotification('org-n', 'u-n', 'x', 'T', 'B');
        // Inside DND → queued for delivery at 08:00 next day
        $this->assertSame(NotificationStatus::PENDING, $r->getStatus());
    }

    // ═══════════════════════════════════════════════════════════════
    // deliverPendingNotifications
    // ═══════════════════════════════════════════════════════════════

    public function testDeliverPendingCountsAll(): void
    {
        $n1 = new Notification('n-1', $this->org, $this->tenant, 'x', 'T', 'B', new \DateTimeImmutable('-1 hour'));
        $n2 = new Notification('n-2', $this->org, $this->tenant, 'y', 'T2', 'B2', new \DateTimeImmutable('-2 hour'));

        $notifRepo = $this->createMock(NotificationRepository::class);
        $notifRepo->method('findPendingDue')->willReturn([$n1, $n2]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $svc = $this->makeService($notifRepo, null, null, $em);
        $this->assertSame(2, $svc->deliverPendingNotifications());
        $this->assertSame(NotificationStatus::DELIVERED, $n1->getStatus());
        $this->assertSame(NotificationStatus::DELIVERED, $n2->getStatus());
    }

    // ═══════════════════════════════════════════════════════════════
    // markRead
    // ═══════════════════════════════════════════════════════════════

    public function testMarkReadUnknownThrows(): void
    {
        $notifRepo = $this->createMock(NotificationRepository::class);
        $notifRepo->method('findByIdAndUser')->willReturn(null);

        $svc = $this->makeService($notifRepo);
        $this->expectException(EntityNotFoundException::class);
        $svc->markRead($this->tenant, 'missing');
    }

    public function testMarkReadOnPendingThrowsInvalidStateTransition(): void
    {
        $n = new Notification('n-pend', $this->org, $this->tenant, 'x', 'T', 'B', new \DateTimeImmutable());
        // status is PENDING by default

        $notifRepo = $this->createMock(NotificationRepository::class);
        $notifRepo->method('findByIdAndUser')->willReturn($n);

        $svc = $this->makeService($notifRepo);
        $this->expectException(InvalidStateTransitionException::class);
        $svc->markRead($this->tenant, 'n-pend');
    }

    public function testMarkReadOnDeliveredSucceeds(): void
    {
        $n = new Notification('n-del', $this->org, $this->tenant, 'x', 'T', 'B', new \DateTimeImmutable());
        $n->markDelivered();

        $notifRepo = $this->createMock(NotificationRepository::class);
        $notifRepo->method('findByIdAndUser')->willReturn($n);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $svc = $this->makeService($notifRepo, null, null, $em);
        $r = $svc->markRead($this->tenant, 'n-del');
        $this->assertSame(NotificationStatus::READ, $r->getStatus());
    }

    // ═══════════════════════════════════════════════════════════════
    // listNotifications
    // ═══════════════════════════════════════════════════════════════

    public function testListNotificationsCapsPerPage(): void
    {
        $notifRepo = $this->createMock(NotificationRepository::class);
        $notifRepo->method('findByUser')->willReturn([]);
        $notifRepo->method('countByUser')->willReturn(250);

        $svc = $this->makeService($notifRepo);
        $r = $svc->listNotifications($this->tenant, 1, 500);
        $this->assertSame(100, $r['meta']['per_page']);
        $this->assertTrue($r['meta']['has_next']);
    }

    // ═══════════════════════════════════════════════════════════════
    // updatePreference
    // ═══════════════════════════════════════════════════════════════

    public function testUpdatePreferenceCreatesNewWhenAbsent(): void
    {
        $prefRepo = $this->createMock(NotificationPreferenceRepository::class);
        $prefRepo->method('findByUserAndEvent')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $svc = $this->makeService(null, $prefRepo, null, $em);
        $p = $svc->updatePreference($this->tenant, 'booking.confirmed', true, '22:00', '07:00');
        $this->assertInstanceOf(NotificationPreference::class, $p);
        $this->assertTrue($p->isEnabled());
        $this->assertSame('22:00', $p->getDndStartLocal());
        $this->assertSame('07:00', $p->getDndEndLocal());
    }

    public function testUpdatePreferenceUpdatesExistingWithoutNewDndTimes(): void
    {
        $existing = new NotificationPreference('np-e', $this->tenant, 'x');
        $prefRepo = $this->createMock(NotificationPreferenceRepository::class);
        $prefRepo->method('findByUserAndEvent')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        // No persist (not new) but flush should be called
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $svc = $this->makeService(null, $prefRepo, null, $em);
        $p = $svc->updatePreference($this->tenant, 'x', false, null, null);
        $this->assertFalse($p->isEnabled());
    }

    public function testGetPreferencesDelegatesToRepo(): void
    {
        $prefRepo = $this->createMock(NotificationPreferenceRepository::class);
        $prefRepo->method('findAllByUser')->willReturn([]);

        $svc = $this->makeService(null, $prefRepo);
        $this->assertSame([], $svc->getPreferences($this->tenant));
    }

    // ═══════════════════════════════════════════════════════════════
    // isInDndWindow (all branches already hit by prior tests, add edge)
    // ═══════════════════════════════════════════════════════════════

    public function testIsInDndWindowExactEndTimeIsExcluded(): void
    {
        $svc = $this->makeService();
        // 08:00 is end — NOT in window
        $this->assertFalse($svc->isInDndWindow('21:00', '08:00', '08:00'));
        // 07:59 is just before end — IS in window
        $this->assertTrue($svc->isInDndWindow('21:00', '08:00', '07:59'));
    }
}
