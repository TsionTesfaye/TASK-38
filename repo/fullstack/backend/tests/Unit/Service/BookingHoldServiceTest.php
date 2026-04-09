<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BookingHoldServiceTest extends TestCase
{
    private BookingHoldRepository&MockObject $holdRepo;
    private InventoryItemRepository&MockObject $itemRepo;
    private PricingService&MockObject $pricingService;
    private IdempotencyService&MockObject $idempotency;
    private ThrottleService&MockObject $throttle;
    private SettingsRepository&MockObject $settingsRepo;
    private BillingService&MockObject $billingService;
    private LedgerService&MockObject $ledgerService;
    private AuditService&MockObject $audit;
    private NotificationService&MockObject $notification;
    private OrganizationScope&MockObject $orgScope;
    private EntityManagerInterface&MockObject $em;
    private BookingHoldService $service;

    protected function setUp(): void
    {
        $this->holdRepo = $this->createMock(BookingHoldRepository::class);
        $this->itemRepo = $this->createMock(InventoryItemRepository::class);
        $this->pricingService = $this->createMock(PricingService::class);
        $this->idempotency = $this->createMock(IdempotencyService::class);
        $this->throttle = $this->createMock(ThrottleService::class);
        $this->settingsRepo = $this->createMock(SettingsRepository::class);
        $this->billingService = $this->createMock(BillingService::class);
        $this->ledgerService = $this->createMock(LedgerService::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->notification = $this->createMock(NotificationService::class);
        $this->orgScope = $this->createMock(OrganizationScope::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->service = new BookingHoldService(
            $this->holdRepo,
            $this->itemRepo,
            $this->pricingService,
            $this->idempotency,
            $this->throttle,
            $this->settingsRepo,
            $this->billingService,
            $this->ledgerService,
            $this->audit,
            $this->notification,
            $this->orgScope,
            $this->em,
        );
    }

    private function makeTenant(string $userId = 'tenant-1'): User&MockObject
    {
        /** @var Organization&MockObject $org */
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($userId);
        $user->method('getRole')->willReturn(UserRole::TENANT);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn('tenant_user');

        return $user;
    }

    private function makeManager(): User&MockObject
    {
        /** @var Organization&MockObject $org */
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('manager-1');
        $user->method('getRole')->willReturn(UserRole::PROPERTY_MANAGER);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn('manager_user');

        return $user;
    }

    // ─── createHold: role check ────────────────────────────────────────────

    public function testCreateHoldThrowsAccessDeniedForNonTenant(): void
    {
        $manager = $this->makeManager();

        $this->expectException(AccessDeniedException::class);
        $this->service->createHold(
            $manager,
            'item-1',
            1,
            new \DateTimeImmutable('2026-05-01T09:00:00+00:00'),
            new \DateTimeImmutable('2026-05-01T12:00:00+00:00'),
            'req-key-abc',
        );
    }

    // ─── getHold ──────────────────────────────────────────────────────────

    public function testGetHoldThrowsNotFoundWhenHoldDoesNotExist(): void
    {
        $tenant = $this->makeTenant();
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');
        $this->holdRepo->method('findByIdAndOrg')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);
        $this->service->getHold($tenant, 'nonexistent-hold-id');
    }

    public function testGetHoldReturnsHoldWhenBelongsToOrg(): void
    {
        $tenant = $this->makeTenant();
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');

        /** @var BookingHold&MockObject $hold */
        $hold = $this->createMock(BookingHold::class);
        $hold->method('getId')->willReturn('hold-1');
        $hold->method('getStatus')->willReturn(BookingHoldStatus::ACTIVE);
        $hold->method('getTenantUserId')->willReturn('tenant-1');

        $this->holdRepo->method('findByIdAndOrg')->with('hold-1', 'org-1')->willReturn($hold);

        $result = $this->service->getHold($tenant, 'hold-1');
        $this->assertSame('hold-1', $result->getId());
    }

    // ─── releaseHold ──────────────────────────────────────────────────────

    public function testReleaseHoldThrowsNotFoundForMissingHold(): void
    {
        $tenant = $this->makeTenant();
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');
        $this->holdRepo->method('findByIdAndOrg')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);
        $this->service->releaseHold($tenant, 'missing-hold');
    }
}
