<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Organization;
use App\Entity\ReconciliationRun;
use App\Entity\User;
use App\Enum\ReconciliationRunStatus;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Repository\BillRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PaymentRepository;
use App\Repository\ReconciliationRunRepository;
use App\Repository\RefundRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\ReconciliationService;
use App\Storage\LocalStorageService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReconciliationServiceTest extends TestCase
{
    private ReconciliationRunRepository&MockObject $runRepo;
    private BillRepository&MockObject $billRepo;
    private PaymentRepository&MockObject $paymentRepo;
    private RefundRepository&MockObject $refundRepo;
    private LedgerEntryRepository&MockObject $ledgerRepo;
    private OrganizationRepository&MockObject $orgRepo;
    private OrganizationScope&MockObject $orgScope;
    private RbacEnforcer&MockObject $rbac;
    private AuditService&MockObject $audit;
    private LocalStorageService&MockObject $storage;
    private EntityManagerInterface&MockObject $em;
    private ReconciliationService $service;

    protected function setUp(): void
    {
        $this->runRepo = $this->createMock(ReconciliationRunRepository::class);
        $this->billRepo = $this->createMock(BillRepository::class);
        $this->paymentRepo = $this->createMock(PaymentRepository::class);
        $this->refundRepo = $this->createMock(RefundRepository::class);
        $this->ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $this->orgRepo = $this->createMock(OrganizationRepository::class);
        $this->orgScope = $this->createMock(OrganizationScope::class);
        $this->rbac = $this->createMock(RbacEnforcer::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->storage = $this->createMock(LocalStorageService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->service = new ReconciliationService(
            $this->runRepo,
            $this->billRepo,
            $this->paymentRepo,
            $this->refundRepo,
            $this->ledgerRepo,
            $this->orgRepo,
            $this->orgScope,
            $this->rbac,
            $this->audit,
            $this->storage,
            $this->em,
        );
    }

    private function makeUser(UserRole $role = UserRole::FINANCE_CLERK): User&MockObject
    {
        /** @var Organization&MockObject $org */
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('getRole')->willReturn($role);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn('finance_user');

        return $user;
    }

    // ─── getRun ───────────────────────────────────────────────────────────

    public function testGetRunThrowsNotFoundForMissingRun(): void
    {
        $user = $this->makeUser();
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');
        $this->runRepo->method('findByIdAndOrg')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);
        $this->service->getRun($user, 'nonexistent-run');
    }

    public function testGetRunThrowsAccessDeniedForTenant(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->rbac->method('enforce')->willThrowException(new AccessDeniedException());

        $this->expectException(AccessDeniedException::class);
        $this->service->getRun($tenant, 'run-1');
    }

    public function testGetRunReturnsRunWhenAuthorized(): void
    {
        $user = $this->makeUser();
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');

        /** @var ReconciliationRun&MockObject $run */
        $run = $this->createMock(ReconciliationRun::class);
        $run->method('getId')->willReturn('run-1');
        $run->method('getStatus')->willReturn(ReconciliationRunStatus::COMPLETED);

        $this->runRepo->method('findByIdAndOrg')->with('run-1', 'org-1')->willReturn($run);

        $result = $this->service->getRun($user, 'run-1');
        $this->assertSame('run-1', $result->getId());
    }

    // ─── exportRunCsv ─────────────────────────────────────────────────────

    public function testExportRunCsvThrowsAccessDeniedForFinanceClerkWithoutExportRole(): void
    {
        // Finance clerk has EXPORT_FINANCE, so this test verifies tenant is blocked
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->rbac->method('enforce')->willThrowException(new AccessDeniedException());

        $this->expectException(AccessDeniedException::class);
        $this->service->exportRunCsv($tenant, 'run-1');
    }

    public function testExportRunCsvReturnsEmptyStringWhenNoCsvPath(): void
    {
        $user = $this->makeUser();
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');

        /** @var ReconciliationRun&MockObject $run */
        $run = $this->createMock(ReconciliationRun::class);
        $run->method('getId')->willReturn('run-1');
        $run->method('getOutputCsvPath')->willReturn(null);

        $this->runRepo->method('findByIdAndOrg')->willReturn($run);

        $result = $this->service->exportRunCsv($user, 'run-1');
        $this->assertSame('', $result);
    }

    public function testExportRunCsvReturnsFileContentWhenCsvPathSet(): void
    {
        $user = $this->makeUser();
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');

        /** @var ReconciliationRun&MockObject $run */
        $run = $this->createMock(ReconciliationRun::class);
        $run->method('getId')->willReturn('run-1');
        $run->method('getOutputCsvPath')->willReturn('exports/reconciliation_org-1_2026-04-07.csv');

        $this->runRepo->method('findByIdAndOrg')->willReturn($run);
        $this->storage->method('getFile')->willReturn("type,bill_id\nbill_mismatch,bill-1\n");

        $result = $this->service->exportRunCsv($user, 'run-1');
        $this->assertStringContainsString('bill_id', $result);
    }

    // ─── listRuns ─────────────────────────────────────────────────────────

    public function testListRunsThrowsAccessDeniedForTenant(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->rbac->method('enforce')->willThrowException(new AccessDeniedException());

        $this->expectException(AccessDeniedException::class);
        $this->service->listRuns($tenant, 1, 20);
    }
}
