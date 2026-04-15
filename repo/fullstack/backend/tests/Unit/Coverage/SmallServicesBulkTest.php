<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\InventoryItem;
use App\Entity\InventoryPricing;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\CapacityMode;
use App\Enum\RateType;
use App\Enum\UserRole;
use App\Exception\CurrencyMismatchException;
use App\Exception\EntityNotFoundException;
use App\Repository\InventoryItemRepository;
use App\Repository\InventoryPricingRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\PricingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for small supporting services whose methods are mostly pure
 * computation (pricing math, rate-type arithmetic). No DB + no HTTP needed.
 */
class SmallServicesBulkTest extends TestCase
{
    private Organization $org;
    private User $admin;
    private User $tenant;
    private InventoryItem $item;

    protected function setUp(): void
    {
        $this->org = new Organization('org-s', 'SMS', 'S Org', 'USD');
        $this->admin = new User('u-a', $this->org, 'a', 'h', 'A', UserRole::ADMINISTRATOR);
        $this->tenant = new User('u-t', $this->org, 't', 'h', 'T', UserRole::TENANT);
        $this->item = new InventoryItem('it-s', $this->org, 'S-1', 'R', 'studio', 'L', CapacityMode::DISCRETE_UNITS, 2, 'UTC');
    }

    private function makeService(
        ?InventoryPricingRepository $pricingRepo = null,
        ?InventoryItemRepository $itemRepo = null,
        ?EntityManagerInterface $em = null,
        ?OrganizationScope $orgScope = null,
    ): PricingService {
        return new PricingService(
            $pricingRepo ?? $this->createMock(InventoryPricingRepository::class),
            $itemRepo ?? $this->createMock(InventoryItemRepository::class),
            $this->createMock(SettingsRepository::class),
            $em ?? $this->createMock(EntityManagerInterface::class),
            $orgScope ?? $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // calculateBookingAmount (RateType match)
    // ═══════════════════════════════════════════════════════════════

    public function testCalculateBookingAmountDaily(): void
    {
        $pricing = new InventoryPricing(
            'p-d', $this->org, $this->item, RateType::DAILY, '100.00', 'USD',
            new \DateTimeImmutable('2026-01-01'),
        );
        $pricingRepo = $this->createMock(InventoryPricingRepository::class);
        $pricingRepo->method('findActiveForItem')->willReturn($pricing);

        $svc = $this->makeService($pricingRepo);
        $amount = $svc->calculateBookingAmount(
            'it-s',
            new \DateTimeImmutable('2026-06-01T10:00:00Z'),
            new \DateTimeImmutable('2026-06-04T10:00:00Z'),
            2,
        );
        // 3 days × $100 × 2 units = $600
        $this->assertSame('600.00', $amount);
    }

    public function testCalculateBookingAmountHourly(): void
    {
        $pricing = new InventoryPricing(
            'p-h', $this->org, $this->item, RateType::HOURLY, '5.00', 'USD',
            new \DateTimeImmutable('2026-01-01'),
        );
        $pricingRepo = $this->createMock(InventoryPricingRepository::class);
        $pricingRepo->method('findActiveForItem')->willReturn($pricing);

        $svc = $this->makeService($pricingRepo);
        $amount = $svc->calculateBookingAmount(
            'it-s',
            new \DateTimeImmutable('2026-06-01T10:00:00Z'),
            new \DateTimeImmutable('2026-06-01T14:00:00Z'),
            1,
        );
        // 4 hours × $5 × 1 = $20
        $this->assertSame('20.00', $amount);
    }

    public function testCalculateBookingAmountMonthly(): void
    {
        $pricing = new InventoryPricing(
            'p-m', $this->org, $this->item, RateType::MONTHLY, '3000.00', 'USD',
            new \DateTimeImmutable('2026-01-01'),
        );
        $pricingRepo = $this->createMock(InventoryPricingRepository::class);
        $pricingRepo->method('findActiveForItem')->willReturn($pricing);

        $svc = $this->makeService($pricingRepo);
        $amount = $svc->calculateBookingAmount(
            'it-s',
            new \DateTimeImmutable('2026-06-01T10:00:00Z'),
            new \DateTimeImmutable('2026-08-01T10:00:00Z'),
            1,
        );
        // ceil(61 days / 30) = 3 months × $3000 = $9000
        $this->assertSame('9000.00', $amount);
    }

    public function testCalculateBookingAmountFlat(): void
    {
        $pricing = new InventoryPricing(
            'p-f', $this->org, $this->item, RateType::FLAT, '99.99', 'USD',
            new \DateTimeImmutable('2026-01-01'),
        );
        $pricingRepo = $this->createMock(InventoryPricingRepository::class);
        $pricingRepo->method('findActiveForItem')->willReturn($pricing);

        $svc = $this->makeService($pricingRepo);
        $amount = $svc->calculateBookingAmount(
            'it-s',
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-05'),
            3,
        );
        // Flat × units: 99.99 × 3 = 299.97
        $this->assertSame('299.97', $amount);
    }

    public function testCalculateBookingAmountNoPricingThrows(): void
    {
        $pricingRepo = $this->createMock(InventoryPricingRepository::class);
        $pricingRepo->method('findActiveForItem')->willReturn(null);

        $svc = $this->makeService($pricingRepo);
        $this->expectException(\DomainException::class);
        $svc->calculateBookingAmount('it-s', new \DateTimeImmutable(), new \DateTimeImmutable('+1 day'), 1);
    }

    // ═══════════════════════════════════════════════════════════════
    // createPricing
    // ═══════════════════════════════════════════════════════════════

    public function testCreatePricingHappyPath(): void
    {
        $itemRepo = $this->createMock(InventoryItemRepository::class);
        $itemRepo->method('findByIdAndOrg')->willReturn($this->item);

        $pricingRepo = $this->createMock(InventoryPricingRepository::class);
        $pricingRepo->method('checkOverlap')->willReturn(false);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-s');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $svc = $this->makeService($pricingRepo, $itemRepo, $em, $orgScope);
        $p = $svc->createPricing(
            $this->admin, 'it-s', 'daily', '100.00', 'USD',
            new \DateTimeImmutable('2026-01-01'), null,
        );
        $this->assertSame('100.00', $p->getAmount());
    }

    public function testCreatePricingCurrencyMismatchThrows(): void
    {
        $itemRepo = $this->createMock(InventoryItemRepository::class);
        $itemRepo->method('findByIdAndOrg')->willReturn($this->item);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-s');

        $svc = $this->makeService(null, $itemRepo, null, $orgScope);
        $this->expectException(CurrencyMismatchException::class);
        // Org default is USD, passing EUR should fail
        $svc->createPricing(
            $this->admin, 'it-s', 'daily', '100.00', 'EUR',
            new \DateTimeImmutable('2026-01-01'), null,
        );
    }

    public function testCreatePricingUnknownItemThrows(): void
    {
        $itemRepo = $this->createMock(InventoryItemRepository::class);
        $itemRepo->method('findByIdAndOrg')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-s');

        $svc = $this->makeService(null, $itemRepo, null, $orgScope);
        $this->expectException(EntityNotFoundException::class);
        $svc->createPricing(
            $this->admin, 'missing', 'daily', '100.00', 'USD',
            new \DateTimeImmutable('2026-01-01'), null,
        );
    }

    public function testCreatePricingOverlapThrows(): void
    {
        $itemRepo = $this->createMock(InventoryItemRepository::class);
        $itemRepo->method('findByIdAndOrg')->willReturn($this->item);

        $pricingRepo = $this->createMock(InventoryPricingRepository::class);
        $pricingRepo->method('checkOverlap')->willReturn(true);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-s');

        $svc = $this->makeService($pricingRepo, $itemRepo, null, $orgScope);
        $this->expectException(\DomainException::class);
        $svc->createPricing(
            $this->admin, 'it-s', 'daily', '100.00', 'USD',
            new \DateTimeImmutable('2026-01-01'), null,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // listPricing
    // ═══════════════════════════════════════════════════════════════

    public function testListPricingReturnsRepoResults(): void
    {
        $itemRepo = $this->createMock(InventoryItemRepository::class);
        $itemRepo->method('findByIdAndOrg')->willReturn($this->item);

        $pricingRepo = $this->createMock(InventoryPricingRepository::class);
        $pricingRepo->method('findByItemId')->willReturn([
            new InventoryPricing(
                'p-list', $this->org, $this->item, RateType::DAILY, '50.00', 'USD',
                new \DateTimeImmutable('2026-01-01'),
            ),
        ]);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-s');

        $svc = $this->makeService($pricingRepo, $itemRepo, null, $orgScope);
        $r = $svc->listPricing($this->admin, 'it-s');
        $this->assertCount(1, $r);
    }

    public function testListPricingUnknownItemThrows(): void
    {
        $itemRepo = $this->createMock(InventoryItemRepository::class);
        $itemRepo->method('findByIdAndOrg')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-s');

        $svc = $this->makeService(null, $itemRepo, null, $orgScope);
        $this->expectException(EntityNotFoundException::class);
        $svc->listPricing($this->admin, 'missing');
    }

    public function testGetActivePricingDelegates(): void
    {
        $pricing = new InventoryPricing(
            'p-a', $this->org, $this->item, RateType::DAILY, '50.00', 'USD',
            new \DateTimeImmutable('2026-01-01'),
        );
        $pricingRepo = $this->createMock(InventoryPricingRepository::class);
        $pricingRepo->method('findActiveForItem')->willReturn($pricing);

        $svc = $this->makeService($pricingRepo);
        $r = $svc->getActivePricing('it-s', new \DateTimeImmutable());
        $this->assertSame($pricing, $r);
    }
}
