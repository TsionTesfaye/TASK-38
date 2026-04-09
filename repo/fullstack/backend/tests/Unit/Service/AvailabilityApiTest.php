<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\InventoryItem;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\CapacityMode;
use App\Enum\UserRole;
use App\Repository\BookingHoldRepository;
use App\Repository\BookingRepository;
use App\Repository\InventoryItemRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\InventoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the availability API contract:
 *   - response includes available_units, requested_units, total_capacity, can_reserve
 *   - can_reserve is true only when requested_units <= available_units
 *   - requested_units > available → can_reserve = false
 *   - 0 available → can_reserve = false
 *   - default requested_units = 1
 */
class AvailabilityApiTest extends TestCase
{
    private InventoryService $service;
    private BookingHoldRepository&MockObject $holdRepo;
    private BookingRepository&MockObject $bookingRepo;

    protected function setUp(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $item = $this->createMock(InventoryItem::class);
        $item->method('getId')->willReturn('item-1');
        $item->method('getTotalCapacity')->willReturn(5);
        $item->method('isActive')->willReturn(true);

        $itemRepo = $this->createMock(InventoryItemRepository::class);
        $itemRepo->method('findByIdAndOrg')->willReturn($item);

        $this->holdRepo = $this->createMock(BookingHoldRepository::class);
        $this->bookingRepo = $this->createMock(BookingRepository::class);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $this->service = new InventoryService(
            $itemRepo,
            $this->holdRepo,
            $this->bookingRepo,
            $this->createMock(EntityManagerInterface::class),
            $orgScope,
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
        );
    }

    private function makeUser(): User&MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('u-1');
        $user->method('getRole')->willReturn(UserRole::TENANT);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        return $user;
    }

    private function setOccupied(int $held, int $booked): void
    {
        $this->holdRepo->method('sumActiveUnitsForItemInRange')->willReturn($held);
        $this->bookingRepo->method('sumActiveUnitsForItemInRange')->willReturn($booked);
    }

    // ─── Response shape ──────────────────────────────────────────

    public function testResponseIncludesAllFields(): void
    {
        $this->setOccupied(0, 0);

        $result = $this->service->checkAvailability(
            $this->makeUser(), 'item-1',
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'),
            2,
        );

        $this->assertArrayHasKey('available_units', $result);
        $this->assertArrayHasKey('requested_units', $result);
        $this->assertArrayHasKey('total_capacity', $result);
        $this->assertArrayHasKey('can_reserve', $result);
    }

    // ─── can_reserve semantics ───────────────────────────────────

    public function testCanReserveTrueWhenRequestedFits(): void
    {
        // capacity=5, held=1, booked=1 → available=3, requesting 2 → fits
        $this->setOccupied(1, 1);

        $result = $this->service->checkAvailability(
            $this->makeUser(), 'item-1',
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'),
            2,
        );

        $this->assertSame(3, $result['available_units']);
        $this->assertSame(2, $result['requested_units']);
        $this->assertSame(5, $result['total_capacity']);
        $this->assertTrue($result['can_reserve']);
    }

    public function testCanReserveFalseWhenRequestedExceedsAvailable(): void
    {
        // capacity=5, held=3, booked=1 → available=1, requesting 2 → doesn't fit
        $this->setOccupied(3, 1);

        $result = $this->service->checkAvailability(
            $this->makeUser(), 'item-1',
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'),
            2,
        );

        $this->assertSame(1, $result['available_units']);
        $this->assertSame(2, $result['requested_units']);
        $this->assertFalse($result['can_reserve']);
    }

    public function testCanReserveFalseWhenZeroAvailable(): void
    {
        // capacity=5, held=3, booked=2 → available=0
        $this->setOccupied(3, 2);

        $result = $this->service->checkAvailability(
            $this->makeUser(), 'item-1',
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'),
            1,
        );

        $this->assertSame(0, $result['available_units']);
        $this->assertFalse($result['can_reserve']);
    }

    public function testCanReserveTrueWhenExactFit(): void
    {
        // capacity=5, held=2, booked=1 → available=2, requesting 2 → exact fit
        $this->setOccupied(2, 1);

        $result = $this->service->checkAvailability(
            $this->makeUser(), 'item-1',
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'),
            2,
        );

        $this->assertSame(2, $result['available_units']);
        $this->assertTrue($result['can_reserve']);
    }

    public function testDefaultRequestedUnitsIsOne(): void
    {
        $this->setOccupied(0, 0);

        $result = $this->service->checkAvailability(
            $this->makeUser(), 'item-1',
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'),
        );

        $this->assertSame(1, $result['requested_units']);
        $this->assertTrue($result['can_reserve']);
    }

    public function testFullCapacityWithSingleUnitRequest(): void
    {
        // capacity=5, all occupied → available=0
        $this->setOccupied(2, 3);

        $result = $this->service->checkAvailability(
            $this->makeUser(), 'item-1',
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'),
        );

        $this->assertSame(0, $result['available_units']);
        $this->assertSame(5, $result['total_capacity']);
        $this->assertFalse($result['can_reserve']);
    }
}
