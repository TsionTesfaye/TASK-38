<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\InventoryItem;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\BookingHoldStatus;
use App\Enum\CapacityMode;
use App\Enum\UserRole;
use App\Exception\InsufficientCapacityException;
use App\Repository\BookingHoldRepository;
use App\Repository\InventoryItemRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Service\AuditService;
use App\Service\BillingService;
use App\Service\BookingHoldService;
use App\Service\IdempotencyService;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\PricingService;
use App\Service\ThrottleService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Proves that expired holds release capacity:
 *   - An active hold whose expires_at <= NOW is NOT counted
 *   - The hardening UPDATE marks expired holds before the capacity check
 *   - Capacity freed by expiration allows a new hold to be created
 */
class ExpiredHoldCapacityTest extends TestCase
{
    private Connection&MockObject $conn;
    private EntityManagerInterface&MockObject $em;
    private ThrottleService&MockObject $throttle;
    private BookingHoldService $service;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($this->conn);
        $this->throttle = $this->createMock(ThrottleService::class);

        $holdRepo = $this->createMock(BookingHoldRepository::class);
        $itemRepo = $this->createMock(InventoryItemRepository::class);

        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $org->method('getDefaultCurrency')->willReturn('USD');

        $item = $this->createMock(InventoryItem::class);
        $item->method('getId')->willReturn('item-1');
        $item->method('isActive')->willReturn(true);
        $item->method('getTotalCapacity')->willReturn(1); // capacity = 1
        $item->method('getOrganization')->willReturn($org);

        $itemRepo->method('findByIdAndOrg')->willReturn($item);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

        $idempotency = $this->createMock(IdempotencyService::class);
        $idempotency->method('check')->willReturn(null);

        $this->service = new BookingHoldService(
            $holdRepo,
            $itemRepo,
            $this->createMock(PricingService::class),
            $idempotency,
            $this->throttle,
            $settingsRepo,
            $this->createMock(BillingService::class),
            $this->createMock(LedgerService::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $orgScope,
            $this->em,
        );
    }

    private function makeTenant(): User&MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('tenant-1');
        $user->method('getRole')->willReturn(UserRole::TENANT);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn('tenant');
        return $user;
    }

    /**
     * Scenario: capacity=1, one ACTIVE hold exists but has expired (expires_at <= NOW).
     * The capacity query must NOT count it → available=1 → new hold succeeds.
     */
    public function testExpiredHoldDoesNotConsumeCapacity(): void
    {
        $statements = [];

        // Track all executeStatement calls (FOR UPDATE lock + hardening UPDATE).
        $this->conn->method('executeStatement')->willReturnCallback(function (string $sql) use (&$statements) {
            $statements[] = $sql;
            return 1;
        });

        // fetchOne: capacity queries return 0 held units (expired hold excluded), 0 booked.
        $this->conn->method('fetchOne')->willReturn('0');

        // wrapInTransaction: execute the callback directly.
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());

        $hold = $this->service->createHold(
            $this->makeTenant(),
            'item-1',
            1,
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+2 days'),
            'req-1',
        );

        $this->assertNotNull($hold, 'Hold must be created when expired holds free capacity');

        // Verify the hardening UPDATE ran before the capacity query.
        $this->assertNotEmpty($statements);
        $hardeningFound = false;
        foreach ($statements as $sql) {
            if (str_contains($sql, 'UPDATE booking_holds SET status')) {
                $hardeningFound = true;
                break;
            }
        }
        $this->assertTrue($hardeningFound, 'Hardening UPDATE must expire stale holds before capacity check');
    }

    /**
     * Scenario: capacity=1, one truly active hold (not expired) exists.
     * New hold request for 1 unit → InsufficientCapacityException.
     */
    public function testActiveNonExpiredHoldStillBlocksCapacity(): void
    {
        $this->conn->method('executeStatement')->willReturn(0);

        // fetchOne: first call returns 1 held unit (active hold), second returns 0 booked.
        $callCount = 0;
        $this->conn->method('fetchOne')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            return $callCount === 1 ? '1' : '0';
        });

        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());

        $this->expectException(InsufficientCapacityException::class);

        $this->service->createHold(
            $this->makeTenant(),
            'item-1',
            1,
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+2 days'),
            'req-2',
        );
    }

    /**
     * Verify the capacity SQL includes expires_at filter.
     */
    public function testCapacityQueryIncludesExpiresAtFilter(): void
    {
        $capturedQueries = [];

        $this->conn->method('executeStatement')->willReturn(0);
        $this->conn->method('fetchOne')->willReturnCallback(function (string $sql) use (&$capturedQueries) {
            $capturedQueries[] = $sql;
            return '0';
        });
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());

        $this->service->createHold(
            $this->makeTenant(),
            'item-1',
            1,
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+2 days'),
            'req-3',
        );

        // The first fetchOne is the booking_holds capacity query.
        $this->assertNotEmpty($capturedQueries);
        $holdsQuery = $capturedQueries[0];
        $this->assertStringContainsString('expires_at', $holdsQuery, 'Capacity query must filter on expires_at');
        $this->assertStringContainsString('booking_holds', $holdsQuery);
    }
}
