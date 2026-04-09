<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Bill;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\CapacityMode;
use App\Enum\LedgerEntryType;
use App\Enum\PaymentStatus;
use App\Enum\RateType;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\InvalidEnumException;
use App\Exception\PaymentValidationException;
use App\Metrics\MetricsCollector;
use App\Metrics\RequestMetricsListener;
use App\Repository\BillRepository;
use App\Repository\PaymentRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use App\Security\OrganizationScope;
use App\Security\PaymentSignatureVerifier;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BackupService;
use App\Service\BillingService;
use App\Service\BookingHoldService;
use App\Service\BookingService;
use App\Service\IdempotencyService;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\PaymentService;
use App\Service\ReconciliationService;
use App\Service\SchedulerService;
use App\Validation\EnumValidator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Final QA hardening tests covering:
 *   1 — Enum validation (500 → 422)
 *   2 — Scheduler metrics wiring
 *   3 — Callback edge cases (bad sig, bad amount, bad currency, duplicate)
 *   4 — Metrics in real flows
 *   5 — Controller-service isolation (verified by audit)
 *   6 — Idempotency proofs
 *   7 — API contract consistency
 */
class FinalHardeningTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // 1. ENUM VALIDATION (500 → 422)
    // ═══════════════════════════════════════════════════════════════

    public function testInvalidUserRoleReturns422WithAllowedValues(): void
    {
        try {
            EnumValidator::validate('superadmin', UserRole::class, 'role');
            $this->fail('Should throw');
        } catch (InvalidEnumException $e) {
            $arr = $e->toArray();
            $this->assertSame(422, $arr['code']);
            $this->assertSame('invalid_enum', $arr['error']);
            $this->assertSame('role', $arr['field']);
            $this->assertContains('administrator', $arr['allowed_values']);
            $this->assertContains('tenant', $arr['allowed_values']);
        }
    }

    public function testInvalidCapacityModeReturns422(): void
    {
        $this->expectException(InvalidEnumException::class);
        EnumValidator::validate('shared_pool', CapacityMode::class, 'capacity_mode');
    }

    public function testInvalidRateTypeReturns422(): void
    {
        $this->expectException(InvalidEnumException::class);
        EnumValidator::validate('weekly', RateType::class, 'rate_type');
    }

    public function testValidEnumPassesThrough(): void
    {
        $this->assertSame(UserRole::TENANT, EnumValidator::validate('tenant', UserRole::class, 'role'));
        $this->assertSame(CapacityMode::DISCRETE_UNITS, EnumValidator::validate('discrete_units', CapacityMode::class, 'capacity_mode'));
        $this->assertSame(RateType::MONTHLY, EnumValidator::validate('monthly', RateType::class, 'rate_type'));
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. SCHEDULER METRICS (tested in SchedulerWorkerCommandTest)
    //    — additional SchedulerService direct tests here
    // ═══════════════════════════════════════════════════════════════

    private function makeSchedulerService(): array
    {
        $holdService = $this->createMock(BookingHoldService::class);
        $billingService = $this->createMock(BillingService::class);
        $reconService = $this->createMock(ReconciliationService::class);
        $notifService = $this->createMock(NotificationService::class);
        $bookingService = $this->createMock(BookingService::class);
        $idempService = $this->createMock(IdempotencyService::class);
        $backupService = $this->createMock(BackupService::class);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findDistinctOrganizationIds')->willReturn([]);
        $metrics = new MetricsCollector();

        $svc = new SchedulerService(
            $holdService, $billingService, $reconService, $notifService,
            $bookingService, $idempService, $backupService, $userRepo,
            $metrics, new NullLogger(),
        );

        return compact('svc', 'metrics', 'holdService', 'billingService',
            'reconService', 'notifService', 'bookingService', 'idempService');
    }

    public function testSchedulerSuccessMetricIncrement(): void
    {
        $s = $this->makeSchedulerService();
        $s['holdService']->method('expireHolds')->willReturn(5);
        $s['notifService']->method('deliverPendingNotifications')->willReturn(0);
        $s['bookingService']->method('evaluateNoShows')->willReturn(0);
        $s['billingService']->method('generateRecurringBills')->willReturn(0);
        $s['idempService']->method('cleanupExpired')->willReturn(0);
        $s['reconService']->method('runDailyReconciliation')->willReturn(0);

        $s['svc']->initLastRun();
        $results = $s['svc']->runCycle(force: true);

        $this->assertSame('ok', $results['expire_holds']['status']);
        $success = $s['metrics']->getSchedulerSuccess();
        $this->assertSame(1, $success['expire_holds']);
    }

    public function testSchedulerFailureMetricIncrement(): void
    {
        $s = $this->makeSchedulerService();
        $s['holdService']->method('expireHolds')->willThrowException(new \RuntimeException('boom'));
        $s['notifService']->method('deliverPendingNotifications')->willReturn(0);
        $s['bookingService']->method('evaluateNoShows')->willReturn(0);
        $s['billingService']->method('generateRecurringBills')->willReturn(0);
        $s['idempService']->method('cleanupExpired')->willReturn(0);
        $s['reconService']->method('runDailyReconciliation')->willReturn(0);

        $s['svc']->initLastRun();
        $results = $s['svc']->runCycle(force: true);

        $this->assertSame('failed', $results['expire_holds']['status']);
        $failure = $s['metrics']->getSchedulerFailure();
        $this->assertSame(1, $failure['expire_holds']);
        // Other tasks must still succeed
        $this->assertSame('ok', $results['deliver_notifications']['status']);
    }

    public function testSchedulerLatencyRecorded(): void
    {
        $s = $this->makeSchedulerService();
        $s['holdService']->method('expireHolds')->willReturn(0);
        $s['notifService']->method('deliverPendingNotifications')->willReturn(0);
        $s['bookingService']->method('evaluateNoShows')->willReturn(0);
        $s['billingService']->method('generateRecurringBills')->willReturn(0);
        $s['idempService']->method('cleanupExpired')->willReturn(0);
        $s['reconService']->method('runDailyReconciliation')->willReturn(0);

        $s['svc']->initLastRun();
        $s['svc']->runCycle(force: true);

        // Latencies were recorded under "scheduler.*" keys
        $summary = $s['metrics']->getSummary();
        $this->assertGreaterThan(0, $summary['latency_p50_ms']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. CALLBACK EDGE CASES
    // ═══════════════════════════════════════════════════════════════

    private function makePaymentServiceWithMocks(
        bool $sigValid = true,
        ?string $billStatus = 'open',
    ): array {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $bill = $this->createMock(Bill::class);
        $bill->method('getId')->willReturn('bill-1');
        $bill->method('getOrganizationId')->willReturn('org-1');
        $bill->method('getBookingId')->willReturn('bk-1');
        $bill->method('getOriginalAmount')->willReturn('100.00');
        $bill->method('getTenantUserId')->willReturn('t-1');

        $payment = new Payment('pay-1', $org, $bill, 'req-1', 'USD', '100.00');

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(function (string $sql) use ($billStatus) {
            if (str_contains($sql, 'payments')) return ['id' => 'pay-1', 'status' => 'pending'];
            if (str_contains($sql, 'bills')) return ['id' => 'bill-1', 'status' => $billStatus];
            return false;
        });
        $conn->method('fetchOne')->willReturn('0');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->method('findByRequestId')->willReturn($payment);

        $sig = $this->createMock(PaymentSignatureVerifier::class);
        $sig->method('verifySignature')->willReturn($sigValid);

        $ledger = $this->createMock(LedgerService::class);
        $billing = $this->createMock(BillingService::class);

        $service = new PaymentService(
            $paymentRepo, $this->createMock(BillRepository::class),
            $this->createMock(SettingsRepository::class),
            $ledger, $billing, $sig,
            $this->createMock(OrganizationScope::class),
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $em,
        );

        return compact('service', 'payment', 'ledger', 'billing');
    }

    public function testCallbackInvalidSignatureRejected(): void
    {
        $m = $this->makePaymentServiceWithMocks(sigValid: false);
        $m['ledger']->expects($this->never())->method('createEntry');

        $this->expectException(PaymentValidationException::class);
        $this->expectExceptionMessage('signature');

        $m['service']->processCallback('req-1', 'bad-sig', [
            'status' => 'succeeded', 'amount' => '100.00', 'currency' => 'USD',
        ]);
    }

    public function testCallbackMismatchedAmountRejected(): void
    {
        $m = $this->makePaymentServiceWithMocks();
        $m['ledger']->expects($this->never())->method('createEntry');

        $this->expectException(PaymentValidationException::class);
        $this->expectExceptionMessage('amount');

        $m['service']->processCallback('req-1', 'sig', [
            'status' => 'succeeded', 'amount' => '999.99', 'currency' => 'USD',
        ]);
    }

    public function testCallbackMismatchedCurrencyRejected(): void
    {
        $m = $this->makePaymentServiceWithMocks();
        $m['ledger']->expects($this->never())->method('createEntry');

        $this->expectException(PaymentValidationException::class);
        $this->expectExceptionMessage('currency');

        $m['service']->processCallback('req-1', 'sig', [
            'status' => 'succeeded', 'amount' => '100.00', 'currency' => 'EUR',
        ]);
    }

    public function testCallbackSuccessCreatesLedgerAndUpdatesBill(): void
    {
        $m = $this->makePaymentServiceWithMocks();
        $m['ledger']->expects($this->once())->method('createEntry')->with(
            'org-1', LedgerEntryType::PAYMENT_RECEIVED, '100.00', 'USD', 'bk-1', 'bill-1', 'pay-1',
        );
        $m['billing']->expects($this->once())->method('updateBillStatus');

        $result = $m['service']->processCallback('req-1', 'sig', [
            'status' => 'succeeded', 'amount' => '100.00', 'currency' => 'USD',
        ]);

        $this->assertSame(PaymentStatus::SUCCEEDED, $result->getStatus());
    }

    // ─── 3b. Duplicate callback idempotency ──────────────────────

    public function testDuplicateCallbackOnTerminalPaymentIsIdempotent(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $bill = $this->createMock(Bill::class);
        $bill->method('getId')->willReturn('bill-1');

        $payment = new Payment('pay-dup', $org, $bill, 'req-dup', 'USD', '100.00');
        // Transition to SUCCEEDED first
        $payment->transitionTo(PaymentStatus::SUCCEEDED);

        $conn = $this->createMock(Connection::class);
        // Return SUCCEEDED status from DB lock query
        $conn->method('fetchAssociative')->willReturn(['id' => 'pay-dup', 'status' => 'succeeded']);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->method('findByRequestId')->willReturn($payment);

        $ledger = $this->createMock(LedgerService::class);
        $ledger->expects($this->never())->method('createEntry');

        $service = new PaymentService(
            $paymentRepo, $this->createMock(BillRepository::class),
            $this->createMock(SettingsRepository::class),
            $ledger, $this->createMock(BillingService::class),
            $this->createMock(PaymentSignatureVerifier::class),
            $this->createMock(OrganizationScope::class),
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $em,
        );

        $result = $service->processCallback('req-dup', 'any-sig', [
            'status' => 'succeeded', 'amount' => '100.00', 'currency' => 'USD',
        ]);

        // Returns same payment, no duplicate ledger
        $this->assertSame(PaymentStatus::SUCCEEDED, $result->getStatus());
        $this->assertSame('pay-dup', $result->getId());
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. METRICS IN REAL FLOWS
    // ═══════════════════════════════════════════════════════════════

    public function testRequestMetricsListenerRecordsBookingFlow(): void
    {
        $collector = new MetricsCollector();
        $listener = new RequestMetricsListener($collector);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $request = Request::create('/api/v1/holds', 'POST');
        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        usleep(500);
        $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 201)));

        $summary = $collector->getSummary();
        $this->assertGreaterThan(0, $summary['latency_p50_ms']);
        $this->assertEmpty($summary['error_counts']); // 201 is not an error
    }

    public function testRequestMetricsListenerRecordsPaymentFlow(): void
    {
        $collector = new MetricsCollector();
        $listener = new RequestMetricsListener($collector);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $request = Request::create('/api/v1/payments/callback', 'POST');
        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 200)));

        $this->assertGreaterThan(0, $collector->getSummary()['latency_p50_ms']);
    }

    public function testRequestMetricsListenerRecords422OnBadEnum(): void
    {
        $collector = new MetricsCollector();
        $listener = new RequestMetricsListener($collector);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $request = Request::create('/api/v1/users', 'POST');
        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 422)));

        $this->assertArrayHasKey('POST /api/v1/users:422', $collector->getSummary()['error_counts']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 6. IDEMPOTENCY PROOFS
    // ═══════════════════════════════════════════════════════════════

    public function testPaymentCallbackIdempotencyNoDoubleLedger(): void
    {
        // Already tested above in testDuplicateCallbackOnTerminalPaymentIsIdempotent
        // This test verifies the contract from a different angle: two sequential calls
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $bill = $this->createMock(Bill::class);
        $bill->method('getId')->willReturn('bill-1');

        $payment = new Payment('pay-x', $org, $bill, 'req-x', 'USD', '50.00');
        $payment->transitionTo(PaymentStatus::SUCCEEDED);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['id' => 'pay-x', 'status' => 'succeeded']);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->method('findByRequestId')->willReturn($payment);

        $ledger = $this->createMock(LedgerService::class);
        // CRITICAL: never called on either invocation
        $ledger->expects($this->never())->method('createEntry');

        $service = new PaymentService(
            $paymentRepo, $this->createMock(BillRepository::class),
            $this->createMock(SettingsRepository::class),
            $ledger, $this->createMock(BillingService::class),
            $this->createMock(PaymentSignatureVerifier::class),
            $this->createMock(OrganizationScope::class),
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $em,
        );

        // Call 1
        $r1 = $service->processCallback('req-x', 'sig', ['status' => 'succeeded', 'amount' => '50.00', 'currency' => 'USD']);
        // Call 2 (idempotent replay)
        $r2 = $service->processCallback('req-x', 'sig', ['status' => 'succeeded', 'amount' => '50.00', 'currency' => 'USD']);

        $this->assertSame($r1->getId(), $r2->getId());
        $this->assertSame(PaymentStatus::SUCCEEDED, $r2->getStatus());
    }

    // ═══════════════════════════════════════════════════════════════
    // 5+7. CROSS-TENANT ISOLATION + API CONTRACT
    // ═══════════════════════════════════════════════════════════════

    public function testTenantCannotAccessOtherTenantsResource(): void
    {
        $rbac = new RbacEnforcer();
        $tenant = $this->createMock(User::class);
        $tenant->method('getId')->willReturn('tenant-A');
        $tenant->method('getRole')->willReturn(UserRole::TENANT);

        $this->expectException(AccessDeniedException::class);
        $rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_OWN, 'tenant-B');
    }

    public function testTenantCanAccessOwnResource(): void
    {
        $rbac = new RbacEnforcer();
        $tenant = $this->createMock(User::class);
        $tenant->method('getId')->willReturn('tenant-A');
        $tenant->method('getRole')->willReturn(UserRole::TENANT);

        $rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_OWN, 'tenant-A');
        $this->addToAssertionCount(1);
    }

    public function testTenantCannotViewFinanceOrProcessRefund(): void
    {
        $rbac = new RbacEnforcer();
        $tenant = $this->createMock(User::class);
        $tenant->method('getId')->willReturn('t-1');
        $tenant->method('getRole')->willReturn(UserRole::TENANT);

        foreach ([RbacEnforcer::ACTION_VIEW_FINANCE, RbacEnforcer::ACTION_PROCESS_REFUND, RbacEnforcer::ACTION_MANAGE_BILLING] as $action) {
            try {
                $rbac->enforce($tenant, $action);
                $this->fail("Tenant must not have $action");
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInvalidEnumExceptionFollowsApiContract(): void
    {
        try {
            EnumValidator::validate('bad', UserRole::class, 'role');
        } catch (InvalidEnumException $e) {
            $arr = $e->toArray();
            // Must follow structured API error contract
            $this->assertArrayHasKey('code', $arr);
            $this->assertArrayHasKey('message', $arr);
            $this->assertArrayHasKey('field', $arr);
            $this->assertArrayHasKey('allowed_values', $arr);
            $this->assertSame(422, $e->getHttpStatusCode());
        }
    }

    public function testMetricsCollectorSchedulerCountersInSummary(): void
    {
        $c = new MetricsCollector();
        $c->incrementSchedulerSuccess('expire_holds');
        $c->incrementSchedulerFailure('run_reconciliation');

        $summary = $c->getSummary();
        $this->assertArrayHasKey('scheduler_success', $summary);
        $this->assertArrayHasKey('scheduler_failure', $summary);
        $this->assertSame(1, $summary['scheduler_success']['expire_holds']);
        $this->assertSame(1, $summary['scheduler_failure']['run_reconciliation']);
        $this->assertSame(1, $summary['failed_job_count']);
    }
}
