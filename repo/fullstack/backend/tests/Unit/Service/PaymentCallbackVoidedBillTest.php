<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Bill;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Enum\PaymentStatus;
use App\Repository\BillRepository;
use App\Repository\PaymentRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\PaymentSignatureVerifier;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BillingService;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\PaymentService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Proves that a payment callback against a voided bill:
 *   - does NOT mark the payment as SUCCEEDED
 *   - does NOT create a ledger entry
 *   - safely rejects the payment
 */
class PaymentCallbackVoidedBillTest extends TestCase
{
    private PaymentRepository&MockObject $paymentRepo;
    private BillRepository&MockObject $billRepo;
    private LedgerService&MockObject $ledgerService;
    private BillingService&MockObject $billingService;
    private PaymentSignatureVerifier&MockObject $signatureVerifier;
    private EntityManagerInterface&MockObject $em;
    private Connection&MockObject $conn;
    private PaymentService $service;

    protected function setUp(): void
    {
        $this->paymentRepo = $this->createMock(PaymentRepository::class);
        $this->billRepo = $this->createMock(BillRepository::class);
        $this->ledgerService = $this->createMock(LedgerService::class);
        $this->billingService = $this->createMock(BillingService::class);
        $this->signatureVerifier = $this->createMock(PaymentSignatureVerifier::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->conn = $this->createMock(Connection::class);

        $this->em->method('getConnection')->willReturn($this->conn);

        $this->service = new PaymentService(
            $this->paymentRepo,
            $this->billRepo,
            $this->createMock(SettingsRepository::class),
            $this->ledgerService,
            $this->billingService,
            $this->signatureVerifier,
            $this->createMock(OrganizationScope::class),
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->em,
        );
    }

    private function makePaymentWithVoidedBill(): Payment
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $bill = $this->createMock(Bill::class);
        $bill->method('getId')->willReturn('bill-1');
        $bill->method('getOrganizationId')->willReturn('org-1');
        $bill->method('getBookingId')->willReturn('booking-1');
        $bill->method('getOriginalAmount')->willReturn('100.00');

        $payment = new Payment('pay-1', $org, $bill, 'req-123', 'USD', '100.00');

        return $payment;
    }

    public function testCallbackOnVoidedBillRejectsPayment(): void
    {
        $payment = $this->makePaymentWithVoidedBill();

        // Step 1: Payment row is found and is PENDING (not terminal).
        $this->conn->method('fetchAssociative')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'payments')) {
                    return ['id' => 'pay-1', 'status' => 'pending'];
                }
                // Bill locked row — status is VOIDED.
                if (str_contains($sql, 'bills')) {
                    return ['id' => 'bill-1', 'status' => 'voided'];
                }
                return false;
            });

        $this->paymentRepo->method('findByRequestId')->willReturn($payment);
        $this->signatureVerifier->method('verifySignature')->willReturn(true);

        // Ledger MUST NOT be called.
        $this->ledgerService->expects($this->never())->method('createEntry');

        // BillingService::updateBillStatus MUST NOT be called.
        $this->billingService->expects($this->never())->method('updateBillStatus');

        $result = $this->service->processCallback('req-123', 'valid-sig', [
            'status' => 'succeeded',
            'amount' => '100.00',
            'currency' => 'USD',
        ]);

        $this->assertSame(PaymentStatus::REJECTED, $result->getStatus(), 'Payment must be REJECTED');
    }

    public function testCallbackOnPaidBillRejectsPayment(): void
    {
        $payment = $this->makePaymentWithVoidedBill();

        $this->conn->method('fetchAssociative')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'payments')) {
                    return ['id' => 'pay-1', 'status' => 'pending'];
                }
                if (str_contains($sql, 'bills')) {
                    return ['id' => 'bill-1', 'status' => 'paid'];
                }
                return false;
            });

        $this->paymentRepo->method('findByRequestId')->willReturn($payment);
        $this->signatureVerifier->method('verifySignature')->willReturn(true);

        $this->ledgerService->expects($this->never())->method('createEntry');
        $this->billingService->expects($this->never())->method('updateBillStatus');

        $result = $this->service->processCallback('req-123', 'valid-sig', [
            'status' => 'succeeded',
            'amount' => '100.00',
            'currency' => 'USD',
        ]);

        $this->assertSame(PaymentStatus::REJECTED, $result->getStatus());
    }

    public function testCallbackOnOpenBillStillSucceeds(): void
    {
        $payment = $this->makePaymentWithVoidedBill();

        $this->conn->method('fetchAssociative')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'payments')) {
                    return ['id' => 'pay-1', 'status' => 'pending'];
                }
                if (str_contains($sql, 'bills')) {
                    return ['id' => 'bill-1', 'status' => 'open'];
                }
                return false;
            });

        // Overpayment check: no prior payments, no refunds.
        $this->conn->method('fetchOne')->willReturn('0');

        $this->paymentRepo->method('findByRequestId')->willReturn($payment);
        $this->signatureVerifier->method('verifySignature')->willReturn(true);

        // Ledger and billing SHOULD be called for valid open bill.
        $this->ledgerService->expects($this->once())->method('createEntry');
        $this->billingService->expects($this->once())->method('updateBillStatus');

        $result = $this->service->processCallback('req-123', 'valid-sig', [
            'status' => 'succeeded',
            'amount' => '100.00',
            'currency' => 'USD',
        ]);

        $this->assertSame(PaymentStatus::SUCCEEDED, $result->getStatus());
    }
}
