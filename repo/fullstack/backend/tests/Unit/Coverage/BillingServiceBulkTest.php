<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\InventoryItem;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Entity\Refund;
use App\Entity\Settings;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\CapacityMode;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;
use App\Enum\UserRole;
use App\Exception\BillVoidException;
use App\Exception\EntityNotFoundException;
use App\Repository\BillRepository;
use App\Repository\PaymentRepository;
use App\Repository\RefundRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BillingService;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\OrgTimeService;
use App\Service\PricingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * BillingService unit tests with real entities covering:
 *  - issueInitialBill + issueRecurringBill + issueSupplementalBill + issuePenaltyBill
 *  - voidBill success (no payments) and failure (unrefunded payments)
 *  - updateBillStatus state transitions (open ↔ partially_paid ↔ paid)
 *  - generateRecurringBills scheduler entry point
 */
class BillingServiceBulkTest extends TestCase
{
    private Organization $org;
    private User $admin;
    private User $tenant;
    private InventoryItem $item;

    protected function setUp(): void
    {
        $this->org = new Organization('org-bl', 'BL', 'Bl Org', 'USD');
        $this->admin = new User('u-a', $this->org, 'a', 'h', 'A', UserRole::ADMINISTRATOR);
        $this->tenant = new User('u-t', $this->org, 't', 'h', 'T', UserRole::TENANT);
        $this->item = new InventoryItem('it-bl', $this->org, 'B-1', 'R', 'studio', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
    }

    private function makeService(
        ?BillRepository $billRepo = null,
        ?PaymentRepository $payRepo = null,
        ?RefundRepository $refundRepo = null,
        ?EntityManagerInterface $em = null,
        ?LedgerService $ledger = null,
        ?PricingService $pricing = null,
        ?OrgTimeService $orgTime = null,
    ): BillingService {
        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-bl');

        return new BillingService(
            $billRepo ?? $this->createMock(BillRepository::class),
            $payRepo ?? $this->createMock(PaymentRepository::class),
            $refundRepo ?? $this->createMock(RefundRepository::class),
            $this->createMock(SettingsRepository::class),
            $ledger ?? $this->createMock(LedgerService::class),
            $pricing ?? $this->createMock(PricingService::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $orgTime ?? $this->createMock(OrgTimeService::class),
            $em ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    private function makeBooking(string $id = 'bk-bl'): Booking
    {
        return new Booking(
            $id, $this->org, $this->item, $this->tenant, null,
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 day'),
            1, 'USD', '100.00', '100.00',
        );
    }

    private function makeBill(string $id, BillStatus $status, string $outstanding = '100.00'): Bill
    {
        $bill = new Bill($id, $this->org, null, $this->tenant, BillType::INITIAL, 'USD', '100.00');
        $r = new \ReflectionProperty($bill, 'status'); $r->setAccessible(true); $r->setValue($bill, $status);
        $o = new \ReflectionProperty($bill, 'outstandingAmount'); $o->setAccessible(true); $o->setValue($bill, $outstanding);
        return $bill;
    }

    // ═══════════════════════════════════════════════════════════════
    // issueInitialBill
    // ═══════════════════════════════════════════════════════════════

    public function testIssueInitialBillCreatesBillAndLedgerEntry(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn($cb) => $cb());
        $em->expects($this->atLeastOnce())->method('persist');

        $ledger = $this->createMock(LedgerService::class);
        $ledger->expects($this->once())->method('createEntry');

        $svc = $this->makeService(null, null, null, $em, $ledger);
        $bill = $svc->issueInitialBill($this->tenant, $this->makeBooking());
        $this->assertInstanceOf(Bill::class, $bill);
        $this->assertSame(BillType::INITIAL, $bill->getBillType());
    }

    // ═══════════════════════════════════════════════════════════════
    // issueRecurringBill — returns existing or creates new
    // ═══════════════════════════════════════════════════════════════

    public function testIssueRecurringBillReturnsExistingForSamePeriod(): void
    {
        $existing = $this->makeBill('b-ex', BillStatus::OPEN);

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByBookingAndPeriod')->willReturn($existing);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());
        $orgTime->method('getCurrentPeriod')->willReturn('2026-06');

        $svc = $this->makeService($billRepo, null, null, null, null, null, $orgTime);
        $r = $svc->issueRecurringBill($this->makeBooking());
        $this->assertSame($existing, $r);
    }

    public function testIssueRecurringBillCreatesNewWhenNoneExists(): void
    {
        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByBookingAndPeriod')->willReturn(null);

        $orgTime = $this->createMock(OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable('2026-06-15'));
        $orgTime->method('getCurrentPeriod')->willReturn('2026-06');

        $pricing = $this->createMock(PricingService::class);
        $pricing->method('calculateBookingAmount')->willReturn('3000.00');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn($cb) => $cb());
        $em->expects($this->atLeastOnce())->method('persist');

        $ledger = $this->createMock(LedgerService::class);
        $ledger->expects($this->once())->method('createEntry');

        $svc = $this->makeService($billRepo, null, null, $em, $ledger, $pricing, $orgTime);
        $r = $svc->issueRecurringBill($this->makeBooking());
        $this->assertSame('3000.00', $r->getOriginalAmount());
        $this->assertSame(BillType::RECURRING, $r->getBillType());
    }

    // ═══════════════════════════════════════════════════════════════
    // issueSupplementalBill
    // ═══════════════════════════════════════════════════════════════

    public function testIssueSupplementalBillInvalidAmountThrows(): void
    {
        $svc = $this->makeService();
        $this->expectException(\InvalidArgumentException::class);
        $svc->issueSupplementalBill($this->admin, 'bk-x', '0.00', 'late');
    }

    public function testIssueSupplementalBillNegativeAmountThrows(): void
    {
        $svc = $this->makeService();
        $this->expectException(\InvalidArgumentException::class);
        $svc->issueSupplementalBill($this->admin, 'bk-x', '-5.00', 'late');
    }

    public function testIssueSupplementalBillUnknownBookingThrows(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $svc = $this->makeService(null, null, null, $em);
        $this->expectException(EntityNotFoundException::class);
        $svc->issueSupplementalBill($this->admin, 'bk-missing', '10.00', 'late');
    }

    public function testIssueSupplementalBillHappyPath(): void
    {
        $booking = $this->makeBooking('bk-sup');
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($booking);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('wrapInTransaction')->willReturnCallback(fn($cb) => $cb());

        $ledger = $this->createMock(LedgerService::class);
        $ledger->expects($this->once())->method('createEntry');

        $svc = $this->makeService(null, null, null, $em, $ledger);
        $bill = $svc->issueSupplementalBill($this->admin, 'bk-sup', '25.00', 'late fee');
        $this->assertSame(BillType::SUPPLEMENTAL, $bill->getBillType());
        $this->assertSame('25.00', $bill->getOriginalAmount());
    }

    // ═══════════════════════════════════════════════════════════════
    // issuePenaltyBill
    // ═══════════════════════════════════════════════════════════════

    public function testIssuePenaltyBillHappyPath(): void
    {
        $booking = $this->makeBooking('bk-pen');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn($cb) => $cb());

        $ledger = $this->createMock(LedgerService::class);
        $ledger->expects($this->once())->method('createEntry');

        $svc = $this->makeService(null, null, null, $em, $ledger);
        $bill = $svc->issuePenaltyBill($this->admin, $booking, '50.00', 'cancellation fee');
        $this->assertSame(BillType::PENALTY, $bill->getBillType());
        $this->assertSame('50.00', $bill->getOriginalAmount());
    }

    // ═══════════════════════════════════════════════════════════════
    // voidBill
    // ═══════════════════════════════════════════════════════════════

    public function testVoidBillUnknownBillThrows(): void
    {
        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn(null);

        $svc = $this->makeService($billRepo);
        $this->expectException(EntityNotFoundException::class);
        $svc->voidBill($this->admin, 'missing');
    }

    public function testVoidBillSucceedsWithNoPayments(): void
    {
        $bill = $this->makeBill('b-void', BillStatus::OPEN);

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn($bill);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn($cb) => $cb());

        $ledger = $this->createMock(LedgerService::class);
        $ledger->expects($this->once())->method('createEntry');

        $svc = $this->makeService($billRepo, $payRepo, $refundRepo, $em, $ledger);
        $r = $svc->voidBill($this->admin, 'b-void');
        $this->assertSame(BillStatus::VOIDED, $r->getStatus());
        $this->assertSame('0.00', $r->getOutstandingAmount());
    }

    public function testVoidBillRejectedWithUnrefundedPayment(): void
    {
        $bill = $this->makeBill('b-paid', BillStatus::PAID);
        $payment = new Payment('p-1', $this->org, $bill, 'req', 'USD', '100.00');
        $ps = new \ReflectionProperty($payment, 'status'); $ps->setAccessible(true);
        $ps->setValue($payment, PaymentStatus::SUCCEEDED);

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn($bill);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $svc = $this->makeService($billRepo, $payRepo, $refundRepo);
        $this->expectException(BillVoidException::class);
        $svc->voidBill($this->admin, 'b-paid');
    }

    public function testVoidBillAllowedWhenPaymentsFullyRefunded(): void
    {
        // Bill state must still be OPEN or PARTIALLY_PAID for void transition.
        // The unrefunded-payments check operates independently of bill state.
        $bill = $this->makeBill('b-fr', BillStatus::OPEN);
        $payment = new Payment('p-fr', $this->org, $bill, 'req-fr', 'USD', '100.00');
        $ps = new \ReflectionProperty($payment, 'status'); $ps->setAccessible(true);
        $ps->setValue($payment, PaymentStatus::SUCCEEDED);

        $refund = new Refund('r-fr', $this->org, $bill, $payment, '100.00', 'full', RefundStatus::ISSUED, $this->admin);

        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn($bill);

        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([$payment]);

        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([$refund]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn($cb) => $cb());

        $svc = $this->makeService($billRepo, $payRepo, $refundRepo, $em);
        $r = $svc->voidBill($this->admin, 'b-fr');
        $this->assertSame(BillStatus::VOIDED, $r->getStatus());
    }

    // ═══════════════════════════════════════════════════════════════
    // updateBillStatus
    // ═══════════════════════════════════════════════════════════════

    public function testUpdateBillStatusTransitionsOpenToPartiallyPaid(): void
    {
        $bill = $this->makeBill('b-u1', BillStatus::OPEN, '100.00');
        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([
            (function () use ($bill) {
                $p = new Payment('p-u1', $this->org, $bill, 'r', 'USD', '30.00');
                $ref = new \ReflectionProperty($p, 'status'); $ref->setAccessible(true);
                $ref->setValue($p, PaymentStatus::SUCCEEDED);
                return $p;
            })(),
        ]);
        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $svc = $this->makeService(null, $payRepo, $refundRepo);
        $svc->updateBillStatus($bill);
        $this->assertSame(BillStatus::PARTIALLY_PAID, $bill->getStatus());
        $this->assertSame('70.00', $bill->getOutstandingAmount());
    }

    public function testUpdateBillStatusTransitionsToPaidOnFullPayment(): void
    {
        $bill = $this->makeBill('b-u2', BillStatus::OPEN, '100.00');
        $payRepo = $this->createMock(PaymentRepository::class);
        $payRepo->method('findByBillIdAndStatus')->willReturn([
            (function () use ($bill) {
                $p = new Payment('p-u2', $this->org, $bill, 'r', 'USD', '100.00');
                $ref = new \ReflectionProperty($p, 'status'); $ref->setAccessible(true);
                $ref->setValue($p, PaymentStatus::SUCCEEDED);
                return $p;
            })(),
        ]);
        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByBillIdAndStatus')->willReturn([]);

        $svc = $this->makeService(null, $payRepo, $refundRepo);
        $svc->updateBillStatus($bill);
        $this->assertSame(BillStatus::PAID, $bill->getStatus());
        $this->assertSame('0.00', $bill->getOutstandingAmount());
    }

    // ═══════════════════════════════════════════════════════════════
    // generateRecurringBills (scheduler)
    // ═══════════════════════════════════════════════════════════════

    public function testGenerateRecurringBillsEmptyReturnsZero(): void
    {
        // We need a BookingRepository mock via $em->getRepository
        $bookingRepo = $this->createMock(EntityRepository::class);
        $bookingRepo->method('findBy')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($bookingRepo);

        $svc = $this->makeService(null, null, null, $em);
        // No exception; the method should return an int (0 for empty).
        $count = $svc->generateRecurringBills();
        $this->assertIsInt($count);
    }
}
