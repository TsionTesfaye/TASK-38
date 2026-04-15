<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\InventoryItem;
use App\Entity\LedgerEntry;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Entity\ReconciliationRun;
use App\Entity\Refund;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\CapacityMode;
use App\Enum\LedgerEntryType;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;
use App\Enum\UserRole;
use App\Repository\BillRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PaymentRepository;
use App\Repository\ReconciliationRunRepository;
use App\Repository\RefundRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BillingService;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\ReconciliationService;
use App\Service\RefundService;
use App\Storage\LocalStorageService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ReconciliationAndRefundServiceTest extends TestCase
{
    private Organization $org;
    private User $admin;
    private User $tenant;
    private InventoryItem $item;

    protected function setUp(): void
    {
        $this->org = new Organization('org-r', 'ORGR', 'Org R', 'USD');
        $this->admin = new User('admin-r', $this->org, 'adminr', 'h', 'Admin', UserRole::ADMINISTRATOR);
        $this->tenant = new User('ten-r', $this->org, 'tenr', 'h', 'Tenant', UserRole::TENANT);
        $this->item = new InventoryItem('item-r', $this->org, 'R-1', 'Room', 'studio', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
    }

    private function makeBill(string $id, string $original, string $outstanding): Bill
    {
        $bill = new Bill(
            $id,
            $this->org,
            null,
            $this->tenant,
            BillType::INITIAL,
            'USD',
            $original,
        );
        // Adjust outstanding via reflection since there's no setter.
        $r = new \ReflectionProperty($bill, 'outstandingAmount');
        $r->setAccessible(true);
        $r->setValue($bill, $outstanding);
        return $bill;
    }

    private function makePayment(Bill $bill, string $amount, PaymentStatus $status = PaymentStatus::SUCCEEDED): Payment
    {
        $payment = new Payment(
            'pay-' . uniqid(),
            $this->org,
            $bill,
            'req-' . uniqid(),
            'USD',
            $amount,
        );
        $r = new \ReflectionProperty($payment, 'status');
        $r->setAccessible(true);
        $r->setValue($payment, $status);
        return $payment;
    }

    private function makeRefund(Bill $bill, string $amount): Refund
    {
        return new Refund(
            'ref-' . uniqid(),
            $this->org,
            $bill,
            null,
            $amount,
            'test refund',
            RefundStatus::ISSUED,
            $this->admin,
        );
    }

    private function makeLedgerEntry(LedgerEntryType $type, string $amount, ?Bill $bill = null): LedgerEntry
    {
        return new LedgerEntry(
            'le-' . uniqid(),
            $this->org,
            $type,
            $amount,
            'USD',
            null,
            $bill,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ReconciliationService.performReconciliation + buildCsv
    // ═══════════════════════════════════════════════════════════════

    public function testReconciliationNoMismatchesCompletes(): void
    {
        $bill = $this->makeBill('b-ok', '100.00', '0.00');
        $payment = $this->makePayment($bill, '100.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByOrgForReconciliation')->willReturn([$bill]);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByBillId')->willReturn([
            $this->makeLedgerEntry(LedgerEntryType::BILL_ISSUED, '100.00', $bill),
            $this->makeLedgerEntry(LedgerEntryType::PAYMENT_RECEIVED, '100.00', $bill),
        ]);

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrgAndDate')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $svc = new ReconciliationService(
            $runRepo,
            $billRepo,
            $payRepo,
            $refundRepo,
            $ledgerRepo,
            $this->createMock(OrganizationRepository::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(LocalStorageService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $run = $svc->runReconciliation($this->admin);
        $this->assertInstanceOf(ReconciliationRun::class, $run);
        $this->assertSame(0, $run->getMismatchCount());
    }

    public function testReconciliationDetectsBillOutstandingMismatch(): void
    {
        // Bill says outstanding=50 but payments-refunds would yield 30
        $bill = $this->makeBill('b-mismatch', '100.00', '50.00');
        $payment = $this->makePayment($bill, '70.00');   // paid 70

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByOrgForReconciliation')->willReturn([$bill]);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByBillId')->willReturn([
            $this->makeLedgerEntry(LedgerEntryType::BILL_ISSUED, '100.00', $bill),
            $this->makeLedgerEntry(LedgerEntryType::PAYMENT_RECEIVED, '70.00', $bill),
        ]);

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrgAndDate')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $storage = $this->createMock(LocalStorageService::class);
        $storage->expects($this->once())->method('storeExport')->willReturn('/tmp/x.csv');

        $svc = new ReconciliationService(
            $runRepo, $billRepo, $payRepo, $refundRepo, $ledgerRepo,
            $this->createMock(OrganizationRepository::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class), $storage,
            $this->createMock(EntityManagerInterface::class),
        );

        $run = $svc->runReconciliation($this->admin);
        $this->assertGreaterThan(0, $run->getMismatchCount());
    }

    public function testReconciliationDetectsLedgerPaymentMismatch(): void
    {
        $bill = $this->makeBill('b-lp', '100.00', '0.00');
        $payment = $this->makePayment($bill, '100.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByOrgForReconciliation')->willReturn([$bill]);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        // Ledger has payment recorded at 80 but actual is 100 → mismatch
        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByBillId')->willReturn([
            $this->makeLedgerEntry(LedgerEntryType::BILL_ISSUED, '100.00', $bill),
            $this->makeLedgerEntry(LedgerEntryType::PAYMENT_RECEIVED, '80.00', $bill),
        ]);

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrgAndDate')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $storage = $this->createMock(LocalStorageService::class);
        $storage->method('storeExport')->willReturn('/tmp/x.csv');

        $svc = new ReconciliationService(
            $runRepo, $billRepo, $payRepo, $refundRepo, $ledgerRepo,
            $this->createMock(OrganizationRepository::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class), $storage,
            $this->createMock(EntityManagerInterface::class),
        );

        $run = $svc->runReconciliation($this->admin);
        $this->assertGreaterThan(0, $run->getMismatchCount());
    }

    public function testReconciliationDetectsLedgerRefundMismatch(): void
    {
        $bill = $this->makeBill('b-lr', '100.00', '80.00');
        $payment = $this->makePayment($bill, '50.00');
        $refund = $this->makeRefund($bill, '30.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByOrgForReconciliation')->willReturn([$bill]);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([$refund]);

        // Ledger refund total disagrees with actual refund
        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByBillId')->willReturn([
            $this->makeLedgerEntry(LedgerEntryType::BILL_ISSUED, '100.00', $bill),
            $this->makeLedgerEntry(LedgerEntryType::PAYMENT_RECEIVED, '50.00', $bill),
            $this->makeLedgerEntry(LedgerEntryType::REFUND_ISSUED, '20.00', $bill),
        ]);

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrgAndDate')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $storage = $this->createMock(LocalStorageService::class);
        $storage->method('storeExport')->willReturn('/tmp/x.csv');

        $svc = new ReconciliationService(
            $runRepo, $billRepo, $payRepo, $refundRepo, $ledgerRepo,
            $this->createMock(OrganizationRepository::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class), $storage,
            $this->createMock(EntityManagerInterface::class),
        );

        $run = $svc->runReconciliation($this->admin);
        $this->assertGreaterThan(0, $run->getMismatchCount());
    }

    public function testReconciliationDetectsLedgerBillAmountMismatch(): void
    {
        $bill = $this->makeBill('b-la', '200.00', '0.00');
        $payment = $this->makePayment($bill, '200.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByOrgForReconciliation')->willReturn([$bill]);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        // Bill was issued at 100 in ledger but actual is 200, plus a voided entry
        // (voided entries must NOT contribute to totals — exercises the BILL_VOIDED branch)
        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByBillId')->willReturn([
            $this->makeLedgerEntry(LedgerEntryType::BILL_ISSUED, '100.00', $bill),
            $this->makeLedgerEntry(LedgerEntryType::BILL_VOIDED, '100.00', $bill),
            $this->makeLedgerEntry(LedgerEntryType::PAYMENT_RECEIVED, '200.00', $bill),
        ]);

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrgAndDate')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $storage = $this->createMock(LocalStorageService::class);
        $storage->method('storeExport')->willReturn('/tmp/x.csv');

        $svc = new ReconciliationService(
            $runRepo, $billRepo, $payRepo, $refundRepo, $ledgerRepo,
            $this->createMock(OrganizationRepository::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class), $storage,
            $this->createMock(EntityManagerInterface::class),
        );

        $run = $svc->runReconciliation($this->admin);
        $this->assertGreaterThan(0, $run->getMismatchCount());
    }

    public function testReconciliationPenaltyAppliedBranchHits(): void
    {
        $bill = $this->makeBill('b-pen', '150.00', '0.00');
        $payment = $this->makePayment($bill, '150.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByOrgForReconciliation')->willReturn([$bill]);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        // Original 150 = 100 bill_issued + 50 penalty_applied
        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByBillId')->willReturn([
            $this->makeLedgerEntry(LedgerEntryType::BILL_ISSUED, '100.00', $bill),
            $this->makeLedgerEntry(LedgerEntryType::PENALTY_APPLIED, '50.00', $bill),
            $this->makeLedgerEntry(LedgerEntryType::PAYMENT_RECEIVED, '150.00', $bill),
        ]);

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrgAndDate')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $svc = new ReconciliationService(
            $runRepo, $billRepo, $payRepo, $refundRepo, $ledgerRepo,
            $this->createMock(OrganizationRepository::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(LocalStorageService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $run = $svc->runReconciliation($this->admin);
        // No mismatches — everything balances
        $this->assertSame(0, $run->getMismatchCount());
    }

    public function testReconciliationOverpaidClampsExpectedToZero(): void
    {
        // Paid more than original — expectedOutstanding would be negative, must clamp to 0
        $bill = $this->makeBill('b-over', '50.00', '0.00');
        $payment = $this->makePayment($bill, '100.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByOrgForReconciliation')->willReturn([$bill]);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByBillId')->willReturn([
            $this->makeLedgerEntry(LedgerEntryType::BILL_ISSUED, '50.00', $bill),
            $this->makeLedgerEntry(LedgerEntryType::PAYMENT_RECEIVED, '100.00', $bill),
        ]);

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrgAndDate')->willReturn(null);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $svc = new ReconciliationService(
            $runRepo, $billRepo, $payRepo, $refundRepo, $ledgerRepo,
            $this->createMock(OrganizationRepository::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(LocalStorageService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $run = $svc->runReconciliation($this->admin);
        // Actual outstanding (0) matches expected (0 after clamp) → no mismatch from that branch
        $this->assertSame(0, $run->getMismatchCount());
    }

    public function testReconciliationReturnsExistingRunForSameDay(): void
    {
        $existing = new ReconciliationRun('run-old', $this->org, new \DateTimeImmutable('today'));

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrgAndDate')->willReturn($existing);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $svc = new ReconciliationService(
            $runRepo,
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->createMock(LedgerEntryRepository::class),
            $this->createMock(OrganizationRepository::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(LocalStorageService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $run = $svc->runReconciliation($this->admin);
        $this->assertSame($existing, $run);
    }

    public function testExportRunCsvEmptyWhenNoPath(): void
    {
        $run = new ReconciliationRun('run-e', $this->org, new \DateTimeImmutable('today'));

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByIdAndOrg')->willReturn($run);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $svc = new ReconciliationService(
            $runRepo,
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->createMock(LedgerEntryRepository::class),
            $this->createMock(OrganizationRepository::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(LocalStorageService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $this->assertSame('', $svc->exportRunCsv($this->admin, 'run-e'));
    }

    public function testExportRunCsvReadsStoredFile(): void
    {
        $run = new ReconciliationRun('run-s', $this->org, new \DateTimeImmutable('today'));
        $run->markCompleted(3, '/tmp/stored.csv');

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByIdAndOrg')->willReturn($run);

        $storage = $this->createMock(LocalStorageService::class);
        $storage->method('getFile')->willReturn('a,b,c');

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $svc = new ReconciliationService(
            $runRepo,
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(RefundRepository::class),
            $this->createMock(LedgerEntryRepository::class),
            $this->createMock(OrganizationRepository::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $storage,
            $this->createMock(EntityManagerInterface::class),
        );

        $this->assertSame('a,b,c', $svc->exportRunCsv($this->admin, 'run-s'));
    }

    public function testRunDailyReconciliationSkipsIfExisting(): void
    {
        $existing = new ReconciliationRun('run-prev', $this->org, new \DateTimeImmutable('today'));

        $orgRepo = $this->createMock(OrganizationRepository::class);
        $orgRepo->method('findAllActive')->willReturn([$this->org]);

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrgAndDate')->willReturn($existing);

        $svc = new ReconciliationService(
            $runRepo,
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

    public function testRunDailyReconciliationProcessesOrg(): void
    {
        $orgRepo = $this->createMock(OrganizationRepository::class);
        $orgRepo->method('findAllActive')->willReturn([$this->org]);

        $runRepo = $this->createMock(ReconciliationRunRepository::class);
        $runRepo->method('findByOrgAndDate')->willReturn(null);

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByOrgForReconciliation')->willReturn([]);

        $svc = new ReconciliationService(
            $runRepo,
            $billRepo,
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

        $this->assertSame(1, $svc->runDailyReconciliation());
    }

    // ═══════════════════════════════════════════════════════════════
    // RefundService.issueRefund — success path
    // ═══════════════════════════════════════════════════════════════

    public function testIssueRefundSucceedsWithinRefundableAmount(): void
    {
        $bill = $this->makeBill('b-ref', '100.00', '0.00');
        $r = new \ReflectionProperty($bill, 'status');
        $r->setAccessible(true);
        $r->setValue($bill, BillStatus::PAID);

        $payment = $this->makePayment($bill, '100.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn($bill);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('beginTransaction');
        $em->expects($this->once())->method('commit');
        $em->expects($this->atLeastOnce())->method('persist');

        $ledgerSvc = $this->createMock(LedgerService::class);
        $ledgerSvc->expects($this->once())->method('createEntry');

        $billingSvc = $this->createMock(BillingService::class);
        $billingSvc->expects($this->once())->method('updateBillStatus');

        $svc = new RefundService(
            $refundRepo, $billRepo, $payRepo,
            $ledgerSvc, $billingSvc,
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $em,
        );

        $refund = $svc->issueRefund($this->admin, 'b-ref', '25.00', 'partial refund');
        $this->assertInstanceOf(Refund::class, $refund);
        $this->assertSame('25.00', $refund->getAmount());
    }

    public function testIssueRefundExceedsRefundableThrows(): void
    {
        $bill = $this->makeBill('b-over', '100.00', '0.00');
        $r = new \ReflectionProperty($bill, 'status');
        $r->setAccessible(true);
        $r->setValue($bill, BillStatus::PAID);

        $payment = $this->makePayment($bill, '50.00');
        $existingRefund = $this->makeRefund($bill, '40.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn($bill);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([$existingRefund]);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $svc = new RefundService(
            $refundRepo, $billRepo, $payRepo,
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        // Refundable = 50 - 40 = 10. Requesting 15 should exceed.
        $this->expectException(\App\Exception\RefundExceededException::class);
        $svc->issueRefund($this->admin, 'b-over', '15.00', 'too much');
    }

    public function testIssueRefundTransitionsPaidToPartiallyRefunded(): void
    {
        $bill = $this->makeBill('b-pr', '100.00', '0.00');
        $r = new \ReflectionProperty($bill, 'status');
        $r->setAccessible(true);
        $r->setValue($bill, BillStatus::PAID);

        $payment = $this->makePayment($bill, '100.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn($bill);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $billing = $this->createMock(BillingService::class);
        $billing->method('updateBillStatus')->willReturnCallback(function ($b) {
            // Simulate BillingService leaving the bill in PAID after partial refund
        });

        $svc = new RefundService(
            $refundRepo, $billRepo, $payRepo,
            $this->createMock(LedgerService::class),
            $billing,
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $svc->issueRefund($this->admin, 'b-pr', '25.00', 'partial');
        $this->assertSame(BillStatus::PARTIALLY_REFUNDED, $bill->getStatus());
    }

    public function testIssueRefundRollsBackOnInnerFailure(): void
    {
        $bill = $this->makeBill('b-fail', '100.00', '0.00');
        $r = new \ReflectionProperty($bill, 'status');
        $r->setAccessible(true);
        $r->setValue($bill, BillStatus::PAID);

        $payment = $this->makePayment($bill, '100.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn($bill);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('beginTransaction');
        $em->expects($this->once())->method('rollback');

        $ledger = $this->createMock(LedgerService::class);
        $ledger->method('createEntry')->willThrowException(new \RuntimeException('boom'));

        $svc = new RefundService(
            $refundRepo, $billRepo, $payRepo,
            $ledger,
            $this->createMock(BillingService::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $em,
        );

        $this->expectException(\RuntimeException::class);
        $svc->issueRefund($this->admin, 'b-fail', '10.00', 'crash');
    }

    public function testIssueRefundPostCommitFailuresDontAbort(): void
    {
        $bill = $this->makeBill('b-post', '100.00', '0.00');
        $r = new \ReflectionProperty($bill, 'status');
        $r->setAccessible(true);
        $r->setValue($bill, BillStatus::PAID);

        $payment = $this->makePayment($bill, '100.00');

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn($bill);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        // AuditService + Notification throw in the post-commit block;
        // the refund must still be returned successfully.
        $audit = $this->createMock(AuditService::class);
        $audit->method('log')->willThrowException(new \RuntimeException('audit fail'));

        $notif = $this->createMock(NotificationService::class);
        $notif->method('createNotification')->willThrowException(new \RuntimeException('notif fail'));

        $svc = new RefundService(
            $refundRepo, $billRepo, $payRepo,
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $orgScope, new RbacEnforcer(),
            $audit, $notif,
            $this->createMock(EntityManagerInterface::class),
        );

        $refund = $svc->issueRefund($this->admin, 'b-post', '10.00', 'ok');
        $this->assertInstanceOf(Refund::class, $refund);
    }

    public function testGetRefundByAdminReturnsEntity(): void
    {
        $bill = $this->makeBill('b-g', '100.00', '0.00');
        $refund = $this->makeRefund($bill, '10.00');

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByIdAndOrg')->willReturn($refund);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-r');

        $svc = new RefundService(
            $refundRepo,
            $this->createMock(BillRepository::class),
            $this->createMock(PaymentRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $orgScope, new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $this->assertSame($refund, $svc->getRefund($this->admin, $refund->getId()));
    }
}
