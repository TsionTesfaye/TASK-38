<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Bill;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Repository\BillRepository;
use App\Repository\PaymentRepository;
use App\Repository\RefundRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BillingService;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\OrgTimeService;
use App\Service\PricingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BillingServiceTest extends TestCase
{
    private BillRepository&MockObject $billRepo;
    private PaymentRepository&MockObject $paymentRepo;
    private RefundRepository&MockObject $refundRepo;
    private LedgerService&MockObject $ledgerService;
    private PricingService&MockObject $pricingService;
    private OrganizationScope&MockObject $orgScope;
    private RbacEnforcer&MockObject $rbac;
    private AuditService&MockObject $audit;
    private NotificationService&MockObject $notification;
    private OrgTimeService&MockObject $timeService;
    private EntityManagerInterface&MockObject $em;
    private BillingService $service;

    protected function setUp(): void
    {
        $this->billRepo = $this->createMock(BillRepository::class);
        $this->paymentRepo = $this->createMock(PaymentRepository::class);
        $this->refundRepo = $this->createMock(RefundRepository::class);
        $this->ledgerService = $this->createMock(LedgerService::class);
        $this->pricingService = $this->createMock(PricingService::class);
        $this->orgScope = $this->createMock(OrganizationScope::class);
        $this->rbac = $this->createMock(RbacEnforcer::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->notification = $this->createMock(NotificationService::class);
        $this->timeService = $this->createMock(OrgTimeService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->timeService->method('now')->willReturn(new \DateTimeImmutable());
        $this->timeService->method('getCurrentPeriod')->willReturn('2026-04');

        $settingsRepo = $this->createMock(\App\Repository\SettingsRepository::class);

        $this->service = new BillingService(
            $this->billRepo,
            $this->paymentRepo,
            $this->refundRepo,
            $settingsRepo,
            $this->ledgerService,
            $this->pricingService,
            $this->orgScope,
            $this->rbac,
            $this->audit,
            $this->notification,
            $this->timeService,
            $this->em,
        );
    }

    private function makeUser(UserRole $role = UserRole::FINANCE_CLERK, string $id = 'user-1'): User&MockObject
    {
        /** @var Organization&MockObject $org */
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $org->method('getDefaultCurrency')->willReturn('USD');

        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRole')->willReturn($role);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn("user_{$id}");

        return $user;
    }

    private function makeBill(BillStatus $status = BillStatus::OPEN, string $tenantId = 'user-1'): Bill&MockObject
    {
        /** @var Bill&MockObject $bill */
        $bill = $this->createMock(Bill::class);
        $bill->method('getId')->willReturn('bill-1');
        $bill->method('getStatus')->willReturn($status);
        $bill->method('getOrganizationId')->willReturn('org-1');
        $bill->method('getTenantUserId')->willReturn($tenantId);
        $bill->method('getOriginalAmount')->willReturn('500.00');
        $bill->method('getOutstandingAmount')->willReturn('500.00');
        $bill->method('getCurrency')->willReturn('USD');
        $bill->method('getBillType')->willReturn(BillType::INITIAL);

        return $bill;
    }

    // ─── getBill ──────────────────────────────────────────────────────────

    public function testGetBillThrowsNotFoundForMissingBill(): void
    {
        $user = $this->makeUser();
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');
        $this->billRepo->method('findByIdAndOrg')->with('missing', 'org-1')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);
        $this->service->getBill($user, 'missing');
    }

    public function testGetBillReturnsBillWhenFoundByManager(): void
    {
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);
        $bill = $this->makeBill();
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');
        $this->billRepo->method('findByIdAndOrg')->willReturn($bill);

        $result = $this->service->getBill($manager, 'bill-1');
        $this->assertSame('bill-1', $result->getId());
    }

    public function testGetBillAllowsTenantToViewOwnBill(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-1');
        $bill = $this->makeBill(BillStatus::OPEN, 'tenant-1'); // bill belongs to this tenant
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');
        $this->billRepo->method('findByIdAndOrg')->willReturn($bill);

        $result = $this->service->getBill($tenant, 'bill-1');
        $this->assertSame('bill-1', $result->getId());
    }

    public function testGetBillThrowsAccessDeniedWhenTenantAccessesOtherBill(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-1');
        $bill = $this->makeBill(BillStatus::OPEN, 'tenant-other'); // bill belongs to different tenant
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');
        $this->billRepo->method('findByIdAndOrg')->willReturn($bill);

        $this->expectException(AccessDeniedException::class);
        $this->service->getBill($tenant, 'bill-1');
    }

    // ─── voidBill ─────────────────────────────────────────────────────────

    public function testVoidBillThrowsAccessDeniedForTenant(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->rbac->method('enforce')->willThrowException(new AccessDeniedException());

        $this->expectException(AccessDeniedException::class);
        $this->service->voidBill($tenant, 'bill-1');
    }

    public function testVoidBillThrowsNotFoundForMissingBill(): void
    {
        $clerk = $this->makeUser(UserRole::FINANCE_CLERK);
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');
        $this->billRepo->method('findByIdAndOrg')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);
        $this->service->voidBill($clerk, 'missing-bill');
    }

    // ─── listBills ────────────────────────────────────────────────────────

    public function testListBillsFiltersTenantToOwnBills(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-1');
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');

        $this->billRepo->method('findByOrg')
            ->with('org-1', $this->callback(fn($f) => ($f['tenant_user_id'] ?? null) === 'tenant-1'), 1, 20)
            ->willReturn([]);
        $this->billRepo->method('countByOrg')
            ->willReturn(0);

        $result = $this->service->listBills($tenant, [], 1, 20);
        $this->assertSame(0, $result['meta']['total']);
    }

    // ─── issueSupplementalBill ────────────────────────────────────────────

    public function testIssueSupplementalBillThrowsAccessDeniedForTenant(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->rbac->method('enforce')->willThrowException(new AccessDeniedException());

        $this->expectException(AccessDeniedException::class);
        $this->service->issueSupplementalBill($tenant, 'booking-1', '500.00', 'Damage fee');
    }
}
