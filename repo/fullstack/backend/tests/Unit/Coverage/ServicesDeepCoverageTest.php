<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\BookingHold;
use App\Entity\InventoryItem;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Entity\Refund;
use App\Entity\Settings;
use App\Entity\Terminal;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Repository\BillRepository;
use App\Repository\BookingEventRepository;
use App\Repository\BookingRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PaymentRepository;
use App\Repository\ReconciliationRunRepository;
use App\Repository\RefundRepository;
use App\Repository\SettingsRepository;
use App\Repository\TerminalPackageTransferRepository;
use App\Repository\TerminalPlaylistRepository;
use App\Repository\TerminalRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BillingService;
use App\Service\BookingHoldService;
use App\Service\BookingService;
use App\Service\LedgerService;
use App\Storage\LocalStorageService;
use App\Service\NotificationService;
use App\Service\OrgTimeService;
use App\Service\PricingService;
use App\Service\ReconciliationService;
use App\Service\RefundService;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ServicesDeepCoverageTest extends TestCase
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
    // RefundService
    // ═══════════════════════════════════════════════════════════════

    public function testRefundServiceInvalidAmount(): void
    {
        $svc = new RefundService(
            $this->createMock(RefundRepository::class),
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(\InvalidArgumentException::class);
        $svc->issueRefund($admin, 'bill-1', '0.00', 'reason');
    }

    public function testRefundServiceNegativeAmount(): void
    {
        $svc = new RefundService(
            $this->createMock(RefundRepository::class),
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(\InvalidArgumentException::class);
        $svc->issueRefund($admin, 'bill-1', '-10.00', 'reason');
    }

    public function testRefundServiceEmptyReason(): void
    {
        $svc = new RefundService(
            $this->createMock(RefundRepository::class),
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(\InvalidArgumentException::class);
        $svc->issueRefund($admin, 'bill-1', '10.00', '   ');
    }

    public function testRefundServiceBillNotFound(): void
    {
        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = new RefundService(
            $this->createMock(RefundRepository::class),
            $billRepo,
            $this->createMock(PaymentRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(EntityNotFoundException::class);
        $svc->issueRefund($admin, 'unknown-bill', '10.00', 'reason');
    }

    public function testRefundServiceTenantCannotRefund(): void
    {
        $svc = new RefundService(
            $this->createMock(RefundRepository::class),
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $tenant = $this->makeUser(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $svc->issueRefund($tenant, 'bill-1', '10.00', 'reason');
    }

    public function testRefundServiceGetRefundNotFound(): void
    {
        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByIdAndOrg')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = new RefundService(
            $refundRepo,
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(EntityNotFoundException::class);
        $svc->getRefund($admin, 'unknown');
    }

    public function testRefundServiceListRefunds(): void
    {
        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByOrg')->willReturn([]);
        $refundRepo->method('countByOrg')->willReturn(0);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = new RefundService(
            $refundRepo,
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $r = $svc->listRefunds($admin, [], 1, 25);
        $this->assertArrayHasKey('data', $r);
        $this->assertArrayHasKey('meta', $r);
    }

    // ═══════════════════════════════════════════════════════════════
    // ReconciliationService — non-DB-touching paths
    // ═══════════════════════════════════════════════════════════════

    public function testReconciliationServiceListRuns(): void
    {
        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrg')->willReturn([]);
        $runRepo->method('countByOrg')->willReturn(0);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = new ReconciliationService(
            $runRepo,
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->createMock(LedgerEntryRepository::class),
            $this->createMock(OrganizationRepository::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(LocalStorageService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $r = $svc->listRuns($admin, 1, 25);
        $this->assertArrayHasKey('data', $r);
        $this->assertSame(1, $r['meta']['page']);
    }

    public function testReconciliationServiceTenantForbidden(): void
    {
        $svc = new ReconciliationService(
            $this->createMock(ReconciliationRunRepository::class),
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->createMock(LedgerEntryRepository::class),
            $this->createMock(OrganizationRepository::class),
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(LocalStorageService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $tenant = $this->makeUser(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $svc->runReconciliation($tenant);
    }

    public function testReconciliationServiceGetRunNotFound(): void
    {
        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByIdAndOrg')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = new ReconciliationService(
            $runRepo,
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->createMock(LedgerEntryRepository::class),
            $this->createMock(OrganizationRepository::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(LocalStorageService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(EntityNotFoundException::class);
        $svc->getRun($admin, 'unknown');
    }

    public function testReconciliationServiceRunDailyReturnsCount(): void
    {
        $orgRepo = $this->createMock(OrganizationRepository::class);
        $orgRepo->method('findAllActive')->willReturn([]);

        $svc = new ReconciliationService(
            $this->createMock(ReconciliationRunRepository::class),
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->createMock(LedgerEntryRepository::class),
            $orgRepo,
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(LocalStorageService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $this->assertSame(0, $svc->runDailyReconciliation());
    }

    // ═══════════════════════════════════════════════════════════════
    // BookingService — RBAC & not found
    // ═══════════════════════════════════════════════════════════════

    private function makeBookingService(
        ?BookingRepository $bookingRepo = null,
        ?OrganizationScope $orgScope = null,
    ): BookingService {
        return new BookingService(
            $bookingRepo ?? $this->createMock(BookingRepository::class),
            $this->createMock(BookingEventRepository::class),
            $this->createMock(SettingsRepository::class),
            $this->createMock(BillingService::class),
            $this->createMock(PricingService::class),
            $this->createMock(BookingHoldService::class),
            $this->createMock(LedgerService::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $orgScope ?? $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(OrgTimeService::class),
        );
    }

    public function testBookingServiceTenantCannotCheckIn(): void
    {
        $svc = $this->makeBookingService();
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $svc->checkIn($tenant, 'bk-1');
    }

    public function testBookingServiceTenantCannotComplete(): void
    {
        $svc = $this->makeBookingService();
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $svc->complete($tenant, 'bk-1');
    }

    public function testBookingServiceTenantCannotMarkNoShow(): void
    {
        $svc = $this->makeBookingService();
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $svc->markNoShow($tenant, 'bk-1');
    }

    public function testBookingServiceGetBookingNotFound(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByIdAndOrg')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeBookingService($bookingRepo, $orgScope);

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(EntityNotFoundException::class);
        $svc->getBooking($admin, 'unknown');
    }

    public function testBookingServiceListBookings(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByOrg')->willReturn([]);
        $bookingRepo->method('countByOrg')->willReturn(0);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeBookingService($bookingRepo, $orgScope);

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $r = $svc->listBookings($admin, [], 1, 25);
        $this->assertArrayHasKey('data', $r);
    }

    public function testBookingServiceEvaluateNoShows(): void
    {
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findActiveNoShows')->willReturn([]);

        $svc = $this->makeBookingService($bookingRepo);
        $this->assertSame(0, $svc->evaluateNoShows());
    }

    // ═══════════════════════════════════════════════════════════════
    // TerminalService
    // ═══════════════════════════════════════════════════════════════

    private function makeTerminalService(
        ?TerminalRepository $terminalRepo = null,
        ?TerminalPlaylistRepository $playlistRepo = null,
        ?TerminalPackageTransferRepository $transferRepo = null,
        ?OrganizationScope $orgScope = null,
        ?SettingsRepository $settingsRepo = null,
    ): TerminalService {
        // Settings mock: enabled terminals by default
        $settings = $this->createMock(Settings::class);
        $settings->method('getTerminalsEnabled')->willReturn(true);
        $settingsRepoMock = $settingsRepo ?? $this->createMock(SettingsRepository::class);
        $settingsRepoMock->method('findByOrganizationId')->willReturn($settings);

        return new TerminalService(
            $terminalRepo ?? $this->createMock(TerminalRepository::class),
            $playlistRepo ?? $this->createMock(TerminalPlaylistRepository::class),
            $transferRepo ?? $this->createMock(TerminalPackageTransferRepository::class),
            $settingsRepoMock,
            $this->createMock(EntityManagerInterface::class),
            $orgScope ?? $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
        );
    }

    public function testTerminalServiceTenantCannotRegister(): void
    {
        $svc = $this->makeTerminalService();
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $svc->registerTerminal($tenant, 'T-01', 'Lobby', 'HQ', 'en', false);
    }

    public function testTerminalServiceGetNotFound(): void
    {
        $terminalRepo = $this->createMock(TerminalRepository::class);
        $terminalRepo->method('findByIdAndOrg')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeTerminalService($terminalRepo, null, null, $orgScope);
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(EntityNotFoundException::class);
        $svc->getTerminal($admin, 'unknown');
    }

    public function testTerminalServiceListTerminals(): void
    {
        $terminalRepo = $this->createMock(TerminalRepository::class);
        $terminalRepo->method('findByOrg')->willReturn([]);
        $terminalRepo->method('countByOrg')->willReturn(0);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeTerminalService($terminalRepo, null, null, $orgScope);
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $r = $svc->listTerminals($admin, [], 1, 25);
        $this->assertArrayHasKey('data', $r);
    }

    public function testTerminalServiceListPlaylists(): void
    {
        $playlistRepo = $this->createMock(TerminalPlaylistRepository::class);
        $playlistRepo->method('findByOrgAndLocation')->willReturn([]);
        $playlistRepo->method('countByOrgAndLocation')->willReturn(0);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeTerminalService(null, $playlistRepo, null, $orgScope);
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $r = $svc->listPlaylists($admin, '', 1, 25);
        $this->assertArrayHasKey('data', $r);
    }

    public function testTerminalServiceGetTransferNotFound(): void
    {
        $transferRepo = $this->createMock(TerminalPackageTransferRepository::class);
        $transferRepo->method('findByIdAndOrg')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = $this->makeTerminalService(null, null, $transferRepo, $orgScope);
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(EntityNotFoundException::class);
        $svc->getTransfer($admin, 'unknown');
    }

    public function testTerminalServiceTenantCannotCreatePlaylist(): void
    {
        $svc = $this->makeTerminalService();
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $svc->createPlaylist($tenant, 'name', 'HQ', 'MON-FRI');
    }

    public function testTerminalServiceTenantCannotInitiateTransfer(): void
    {
        $svc = $this->makeTerminalService();
        $tenant = $this->makeUser(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $svc->initiateTransfer($tenant, 'term-1', 'pkg.zip', str_repeat('a', 64), 3);
    }

    // ═══════════════════════════════════════════════════════════════
    // BillingService
    // ═══════════════════════════════════════════════════════════════

    public function testBillingServiceGetBillNotFound(): void
    {
        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = new BillingService(
            $billRepo,
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->createMock(SettingsRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(PricingService::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(OrgTimeService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->expectException(EntityNotFoundException::class);
        $svc->getBill($admin, 'unknown');
    }

    public function testBillingServiceListBills(): void
    {
        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByOrg')->willReturn([]);
        $billRepo->method('countByOrg')->willReturn(0);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $svc = new BillingService(
            $billRepo,
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->createMock(SettingsRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(PricingService::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(OrgTimeService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $r = $svc->listBills($admin, [], 1, 25);
        $this->assertArrayHasKey('data', $r);
    }

    public function testBillingServiceTenantCannotVoid(): void
    {
        $svc = new BillingService(
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->createMock(SettingsRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(PricingService::class),
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(OrgTimeService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $tenant = $this->makeUser(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $svc->voidBill($tenant, 'bill-1');
    }
}
