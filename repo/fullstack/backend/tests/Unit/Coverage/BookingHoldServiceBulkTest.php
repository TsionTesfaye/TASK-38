<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\BookingHold;
use App\Entity\InventoryItem;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\BookingHoldStatus;
use App\Enum\CapacityMode;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * BookingHoldService unit tests for RBAC + lookups + expireHolds + getHold.
 * createHold/confirmHold require DB-level locking so they stay in integration
 * tests — but release/get/expire can be fully unit-tested.
 */
class BookingHoldServiceBulkTest extends TestCase
{
    private Organization $org;
    private User $admin;
    private User $tenant;
    private User $otherTenant;
    private InventoryItem $item;

    protected function setUp(): void
    {
        $this->org = new Organization('org-bh', 'BH', 'BH Org', 'USD');
        $this->admin = new User('u-a', $this->org, 'a', 'h', 'A', UserRole::ADMINISTRATOR);
        $this->tenant = new User('u-t', $this->org, 't', 'h', 'T', UserRole::TENANT);
        $this->otherTenant = new User('u-o', $this->org, 'o', 'h', 'O', UserRole::TENANT);
        $this->item = new InventoryItem('it-bh', $this->org, 'BH-1', 'R', 'studio', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
    }

    private function makeService(
        ?BookingHoldRepository $holdRepo = null,
        ?EntityManagerInterface $em = null,
    ): BookingHoldService {
        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-bh');

        return new BookingHoldService(
            $holdRepo ?? $this->createMock(BookingHoldRepository::class),
            $this->createMock(InventoryItemRepository::class),
            $this->createMock(PricingService::class),
            $this->createMock(IdempotencyService::class),
            $this->createMock(ThrottleService::class),
            $this->createMock(SettingsRepository::class),
            $this->createMock(BillingService::class),
            $this->createMock(LedgerService::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $orgScope,
            $em ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    private function makeHold(BookingHoldStatus $status = BookingHoldStatus::ACTIVE, ?User $tenant = null): BookingHold
    {
        $hold = new BookingHold(
            'h-bh',
            $this->org,
            $this->item,
            $tenant ?? $this->tenant,
            'req',
            1,
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+2 day'),
            new \DateTimeImmutable('+10 minutes'),
        );
        if ($status !== BookingHoldStatus::ACTIVE) {
            $hold->transitionTo($status);
        }
        return $hold;
    }

    // ═══════════════════════════════════════════════════════════════
    // createHold RBAC
    // ═══════════════════════════════════════════════════════════════

    public function testCreateHoldRejectsNonTenant(): void
    {
        $svc = $this->makeService();
        $this->expectException(AccessDeniedException::class);
        $svc->createHold(
            $this->admin, 'it-bh', 1,
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+2 day'),
            'req-x',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // getHold
    // ═══════════════════════════════════════════════════════════════

    public function testGetHoldUnknownThrows(): void
    {
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findByIdAndOrg')->willReturn(null);

        $svc = $this->makeService($repo);
        $this->expectException(EntityNotFoundException::class);
        $svc->getHold($this->admin, 'missing');
    }

    public function testGetHoldByAdminReturnsEntity(): void
    {
        $hold = $this->makeHold();
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($hold);

        $svc = $this->makeService($repo);
        $this->assertSame($hold, $svc->getHold($this->admin, 'h-bh'));
    }

    public function testGetHoldByOtherTenantDenied(): void
    {
        $hold = $this->makeHold(BookingHoldStatus::ACTIVE, $this->tenant);
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($hold);

        $svc = $this->makeService($repo);
        $this->expectException(AccessDeniedException::class);
        $svc->getHold($this->otherTenant, 'h-bh');
    }

    public function testGetHoldByOwningTenantReturnsEntity(): void
    {
        $hold = $this->makeHold(BookingHoldStatus::ACTIVE, $this->tenant);
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($hold);

        $svc = $this->makeService($repo);
        $this->assertSame($hold, $svc->getHold($this->tenant, 'h-bh'));
    }

    // ═══════════════════════════════════════════════════════════════
    // releaseHold
    // ═══════════════════════════════════════════════════════════════

    public function testReleaseHoldUnknownThrows(): void
    {
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findByIdAndOrg')->willReturn(null);

        $svc = $this->makeService($repo);
        $this->expectException(EntityNotFoundException::class);
        $svc->releaseHold($this->admin, 'missing');
    }

    public function testReleaseHoldByOtherTenantDenied(): void
    {
        $hold = $this->makeHold(BookingHoldStatus::ACTIVE, $this->tenant);
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($hold);

        $svc = $this->makeService($repo);
        $this->expectException(AccessDeniedException::class);
        $svc->releaseHold($this->otherTenant, 'h-bh');
    }

    public function testReleaseHoldNonActiveThrows(): void
    {
        $hold = $this->makeHold(BookingHoldStatus::EXPIRED);
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($hold);

        $svc = $this->makeService($repo);
        $this->expectException(\DomainException::class);
        $svc->releaseHold($this->admin, 'h-bh');
    }

    public function testReleaseHoldHappyPath(): void
    {
        $hold = $this->makeHold(BookingHoldStatus::ACTIVE);
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($hold);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $svc = $this->makeService($repo, $em);
        $svc->releaseHold($this->admin, 'h-bh');
        $this->assertSame(BookingHoldStatus::RELEASED, $hold->getStatus());
    }

    public function testReleaseHoldByOwningTenantSucceeds(): void
    {
        $hold = $this->makeHold(BookingHoldStatus::ACTIVE, $this->tenant);
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findByIdAndOrg')->willReturn($hold);

        $em = $this->createMock(EntityManagerInterface::class);

        $svc = $this->makeService($repo, $em);
        $svc->releaseHold($this->tenant, 'h-bh');
        $this->assertSame(BookingHoldStatus::RELEASED, $hold->getStatus());
    }

    // ═══════════════════════════════════════════════════════════════
    // expireHolds — scheduler entry
    // ═══════════════════════════════════════════════════════════════

    public function testExpireHoldsEmpty(): void
    {
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findExpiredActive')->willReturn([]);

        $svc = $this->makeService($repo);
        $this->assertSame(0, $svc->expireHolds());
    }

    public function testExpireHoldsProcessesAll(): void
    {
        $hold1 = $this->makeHold();
        $hold2 = $this->makeHold();
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findExpiredActive')->willReturn([$hold1, $hold2]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn($cb) => $cb());

        $svc = $this->makeService($repo, $em);
        $this->assertSame(2, $svc->expireHolds());
        $this->assertSame(BookingHoldStatus::EXPIRED, $hold1->getStatus());
        $this->assertSame(BookingHoldStatus::EXPIRED, $hold2->getStatus());
    }

    public function testExpireHoldsSwallowsPerHoldFailure(): void
    {
        $good = $this->makeHold();
        $bad = $this->makeHold();
        $repo = $this->createMock(BookingHoldRepository::class);
        $repo->method('findExpiredActive')->willReturn([$bad, $good]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(function ($cb) use ($bad) {
            // Simulate the first hold's transition failing
            static $calls = 0;
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('fail once');
            }
            return $cb();
        });

        $svc = $this->makeService($repo, $em);
        $this->assertSame(1, $svc->expireHolds());
    }
}
