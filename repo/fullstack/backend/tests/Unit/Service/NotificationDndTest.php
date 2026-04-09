<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\NotificationStatus;
use App\Enum\UserRole;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\NotificationRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Service\NotificationService;
use App\Service\OrgTimeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests DND window behavior through the REAL NotificationService — no logic replicas.
 *
 * Section 1: isInDndWindow() — calls the real service method directly
 * Section 2: createNotification() — verifies DND affects scheduling/delivery
 */
class NotificationDndTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════

    private function makeService(
        ?NotificationPreference $pref = null,
        string $currentLocalTime = '12:00',
    ): NotificationService {
        $prefRepo = $this->createMock(NotificationPreferenceRepository::class);
        $prefRepo->method('findByUserAndEvent')->willReturn($pref);

        $orgTimeService = $this->createMock(OrgTimeService::class);
        $orgTimeService->method('now')->willReturn(new \DateTimeImmutable('2026-04-09 ' . $currentLocalTime));
        $orgTimeService->method('getCurrentLocalTime')->willReturn($currentLocalTime);

        $em = $this->createMock(EntityManagerInterface::class);

        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $org->method('getDefaultCurrency')->willReturn('USD');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');

        $em->method('find')->willReturn($user);

        return new NotificationService(
            $this->createMock(NotificationRepository::class),
            $prefRepo,
            $this->createMock(SettingsRepository::class),
            $em,
            $this->createMock(OrganizationScope::class),
            $orgTimeService,
        );
    }

    private function makePref(string $dndStart = '21:00', string $dndEnd = '08:00', bool $enabled = true): NotificationPreference
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $user = new User('user-1', $org, 'test', 'hash', 'Test', UserRole::TENANT);
        $pref = new NotificationPreference('pref-1', $user, 'booking.confirmed');
        $pref->setDndStartLocal($dndStart);
        $pref->setDndEndLocal($dndEnd);
        $pref->setIsEnabled($enabled);
        return $pref;
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION 1: isInDndWindow() — DIRECT SERVICE METHOD CALLS
    // (No logic replica — calls the REAL service method)
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultDndWindow9pmTo8am(): void
    {
        $service = $this->makeService();

        // In DND
        $this->assertTrue($service->isInDndWindow('21:00', '08:00', '22:00'), '10pm is in DND');
        $this->assertTrue($service->isInDndWindow('21:00', '08:00', '23:59'), '11:59pm is in DND');
        $this->assertTrue($service->isInDndWindow('21:00', '08:00', '00:00'), 'midnight is in DND');
        $this->assertTrue($service->isInDndWindow('21:00', '08:00', '07:59'), '7:59am is in DND');
        $this->assertTrue($service->isInDndWindow('21:00', '08:00', '21:00'), '9pm exact is in DND');

        // Not in DND
        $this->assertFalse($service->isInDndWindow('21:00', '08:00', '08:00'), '8am is NOT in DND');
        $this->assertFalse($service->isInDndWindow('21:00', '08:00', '12:00'), 'noon is NOT in DND');
        $this->assertFalse($service->isInDndWindow('21:00', '08:00', '20:59'), '8:59pm is NOT in DND');
    }

    public function testSameStartEndDisablesDnd(): void
    {
        $service = $this->makeService();
        $this->assertFalse($service->isInDndWindow('21:00', '21:00', '21:00'));
        $this->assertFalse($service->isInDndWindow('08:00', '08:00', '12:00'));
    }

    public function testSameDayDndWindow(): void
    {
        $service = $this->makeService();
        // DND 1pm to 3pm (no midnight crossing)
        $this->assertTrue($service->isInDndWindow('13:00', '15:00', '14:00'));
        $this->assertFalse($service->isInDndWindow('13:00', '15:00', '15:00'));
        $this->assertFalse($service->isInDndWindow('13:00', '15:00', '12:59'));
    }

    public function testBoundaryExactStartIsInDnd(): void
    {
        $service = $this->makeService();
        // Exact start time IS in DND
        $this->assertTrue($service->isInDndWindow('21:00', '08:00', '21:00'));
        $this->assertTrue($service->isInDndWindow('13:00', '15:00', '13:00'));
    }

    public function testBoundaryExactEndIsNotInDnd(): void
    {
        $service = $this->makeService();
        // Exact end time is NOT in DND (exclusive)
        $this->assertFalse($service->isInDndWindow('21:00', '08:00', '08:00'));
        $this->assertFalse($service->isInDndWindow('13:00', '15:00', '15:00'));
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION 2: createNotification() — DND AFFECTS DELIVERY
    // (Tests the full service flow, not isolated logic)
    // ═══════════════════════════════════════════════════════════════

    public function testNotificationDeliveredImmediatelyOutsideDnd(): void
    {
        // 12:00 noon is outside default DND (21:00-08:00)
        $pref = $this->makePref('21:00', '08:00');
        $service = $this->makeService($pref, '12:00');

        $notification = $service->createNotification('org-1', 'user-1', 'booking.confirmed', 'Booking Confirmed', 'Your booking is confirmed');

        $this->assertSame(NotificationStatus::DELIVERED, $notification->getStatus());
    }

    public function testNotificationDeferredInsideDnd(): void
    {
        // 23:00 is inside default DND (21:00-08:00)
        $pref = $this->makePref('21:00', '08:00');
        $service = $this->makeService($pref, '23:00');

        $notification = $service->createNotification('org-1', 'user-1', 'booking.confirmed', 'Booking Confirmed', 'Your booking is confirmed');

        // Must remain PENDING (not delivered)
        $this->assertSame(NotificationStatus::PENDING, $notification->getStatus());
    }

    public function testNotificationDeferredAtMidnightInDnd(): void
    {
        // 00:00 midnight is inside default DND (21:00-08:00)
        $pref = $this->makePref('21:00', '08:00');
        $service = $this->makeService($pref, '00:00');

        $notification = $service->createNotification('org-1', 'user-1', 'booking.confirmed', 'Title', 'Body');

        $this->assertSame(NotificationStatus::PENDING, $notification->getStatus());
    }

    public function testCustomDndWindowRespected(): void
    {
        // Custom DND: 1pm-3pm. Current time: 2pm (in DND)
        $pref = $this->makePref('13:00', '15:00');
        $service = $this->makeService($pref, '14:00');

        $notification = $service->createNotification('org-1', 'user-1', 'booking.confirmed', 'Title', 'Body');

        $this->assertSame(NotificationStatus::PENDING, $notification->getStatus());
    }

    public function testCustomDndWindowDelivers(): void
    {
        // Custom DND: 1pm-3pm. Current time: 3pm (outside DND)
        $pref = $this->makePref('13:00', '15:00');
        $service = $this->makeService($pref, '15:00');

        $notification = $service->createNotification('org-1', 'user-1', 'booking.confirmed', 'Title', 'Body');

        $this->assertSame(NotificationStatus::DELIVERED, $notification->getStatus());
    }

    public function testDefaultDndUsedWhenNoPreference(): void
    {
        // No preference → defaults 21:00-08:00. Current: 22:00 (in DND)
        $service = $this->makeService(null, '22:00');

        $notification = $service->createNotification('org-1', 'user-1', 'booking.confirmed', 'Title', 'Body');

        $this->assertSame(NotificationStatus::PENDING, $notification->getStatus());
    }

    public function testDefaultDndDeliversOutsideWindow(): void
    {
        // No preference → defaults 21:00-08:00. Current: 12:00 (outside DND)
        $service = $this->makeService(null, '12:00');

        $notification = $service->createNotification('org-1', 'user-1', 'booking.confirmed', 'Title', 'Body');

        $this->assertSame(NotificationStatus::DELIVERED, $notification->getStatus());
    }

    public function testDisabledNotificationPreference(): void
    {
        // Preference exists but is disabled
        $pref = $this->makePref('21:00', '08:00', false);
        $service = $this->makeService($pref, '12:00');

        $notification = $service->createNotification('org-1', 'user-1', 'booking.confirmed', 'Title', 'Body');

        // Disabled preference → notification still created but not persisted (early return)
        // The service returns a notification object without persisting it
        $this->assertSame(NotificationStatus::PENDING, $notification->getStatus());
    }
}
