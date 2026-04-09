<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Entity\Refund;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Repository\BillRepository;
use App\Repository\BookingRepository;
use App\Repository\PaymentRepository;
use App\Repository\RefundRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BillingService;
use App\Service\BookingService;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\OrgTimeService;
use App\Service\PaymentService;
use App\Service\PricingService;
use App\Service\RefundService;
use App\Repository\SettingsRepository;
use App\Security\PaymentSignatureVerifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tenant isolation: verifies that TENANT-role users cannot access data
 * belonging to other tenants. All paths covered: GET single entity and
 * LIST paginated queries.
 *
 * Security contract:
 * - TENANT can only see their own bills, bookings, payments, refunds.
 * - Cross-tenant ID access returns 403 (AccessDeniedException).
 * - Admin/finance roles can see all records within the organization.
 */
class TenantIsolationTest extends TestCase
{
    // ─── Shared mocks ─────────────────────────────────────────────────────

    private OrganizationScope&MockObject $orgScope;
    private RbacEnforcer&MockObject $rbac;
    private AuditService&MockObject $audit;
    private NotificationService&MockObject $notification;
    private EntityManagerInterface&MockObject $em;
    private LedgerService&MockObject $ledger;

    protected function setUp(): void
    {
        $this->orgScope = $this->createMock(OrganizationScope::class);
        $this->orgScope->method('getOrganizationId')->willReturn('org-1');

        $this->rbac = $this->createMock(RbacEnforcer::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->notification = $this->createMock(NotificationService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->ledger = $this->createMock(LedgerService::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function makeUser(UserRole $role, string $userId = 'tenant-1'): User&MockObject
    {
        /** @var Organization&MockObject $org */
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($userId);
        $user->method('getRole')->willReturn($role);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn("user_{$userId}");

        return $user;
    }

    private function makeBill(string $tenantId): Bill&MockObject
    {
        /** @var Bill&MockObject $bill */
        $bill = $this->createMock(Bill::class);
        $bill->method('getId')->willReturn('bill-1');
        $bill->method('getTenantUserId')->willReturn($tenantId);
        $bill->method('getOrganizationId')->willReturn('org-1');

        return $bill;
    }

    private function makeBooking(string $tenantId): Booking&MockObject
    {
        /** @var Booking&MockObject $booking */
        $booking = $this->createMock(Booking::class);
        $booking->method('getId')->willReturn('booking-1');
        $booking->method('getTenantUserId')->willReturn($tenantId);
        $booking->method('getOrganizationId')->willReturn('org-1');

        return $booking;
    }

    private function makePayment(string $billTenantId): Payment&MockObject
    {
        $bill = $this->makeBill($billTenantId);

        /** @var Payment&MockObject $payment */
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn('payment-1');
        $payment->method('getBill')->willReturn($bill);
        $payment->method('getOrganizationId')->willReturn('org-1');

        return $payment;
    }

    private function makeRefund(string $billTenantId): Refund&MockObject
    {
        $bill = $this->makeBill($billTenantId);

        /** @var Refund&MockObject $refund */
        $refund = $this->createMock(Refund::class);
        $refund->method('getId')->willReturn('refund-1');
        $refund->method('getBill')->willReturn($bill);
        $refund->method('getOrganizationId')->willReturn('org-1');

        return $refund;
    }

    // ─── Bill: GET isolation ───────────────────────────────────────────────

    public function testTenantCannotGetBillOwnedByAnotherTenant(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');
        $bill = $this->makeBill('tenant-B'); // owned by different tenant

        /** @var BillRepository&MockObject $billRepo */
        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->with('bill-1', 'org-1')->willReturn($bill);

        $service = $this->makeBillingService($billRepo);

        $this->expectException(AccessDeniedException::class);
        $service->getBill($tenant, 'bill-1');
    }

    public function testTenantCanGetOwnBill(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');
        $bill = $this->makeBill('tenant-A');

        /** @var BillRepository&MockObject $billRepo */
        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn($bill);

        $service = $this->makeBillingService($billRepo);

        $result = $service->getBill($tenant, 'bill-1');
        $this->assertSame('bill-1', $result->getId());
    }

    public function testAdminCanGetAnyTenantsBill(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR, 'admin-1');
        $bill = $this->makeBill('tenant-B'); // belongs to a different tenant, admin should still see it

        /** @var BillRepository&MockObject $billRepo */
        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->willReturn($bill);

        $service = $this->makeBillingService($billRepo);

        $result = $service->getBill($admin, 'bill-1');
        $this->assertSame('bill-1', $result->getId());
    }

    // ─── Bill: LIST isolation ──────────────────────────────────────────────

    public function testListBillsForTenantInjectsTenantUserIdFilter(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');

        /** @var BillRepository&MockObject $billRepo */
        $billRepo = $this->createMock(BillRepository::class);

        // Assert the tenant_user_id filter is ALWAYS present in the query
        $billRepo->expects($this->once())
            ->method('findByOrg')
            ->with(
                'org-1',
                $this->callback(fn($f) => ($f['tenant_user_id'] ?? null) === 'tenant-A'),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([]);
        $billRepo->method('countByOrg')->willReturn(0);

        $service = $this->makeBillingService($billRepo);
        $service->listBills($tenant, [], 1, 20);
    }

    public function testListBillsForAdminDoesNotInjectTenantFilter(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR, 'admin-1');

        /** @var BillRepository&MockObject $billRepo */
        $billRepo = $this->createMock(BillRepository::class);

        $billRepo->expects($this->once())
            ->method('findByOrg')
            ->with(
                'org-1',
                $this->callback(fn($f) => !isset($f['tenant_user_id'])),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([]);
        $billRepo->method('countByOrg')->willReturn(0);

        $service = $this->makeBillingService($billRepo);
        $service->listBills($admin, [], 1, 20);
    }

    // ─── Booking: GET isolation ────────────────────────────────────────────

    public function testTenantCannotGetBookingOwnedByAnotherTenant(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');
        $booking = $this->makeBooking('tenant-B');

        /** @var BookingRepository&MockObject $bookingRepo */
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $service = $this->makeBookingService($bookingRepo);

        $this->expectException(AccessDeniedException::class);
        $service->getBooking($tenant, 'booking-1');
    }

    public function testTenantCanGetOwnBooking(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');
        $booking = $this->makeBooking('tenant-A');

        /** @var BookingRepository&MockObject $bookingRepo */
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $service = $this->makeBookingService($bookingRepo);

        $result = $service->getBooking($tenant, 'booking-1');
        $this->assertSame('booking-1', $result->getId());
    }

    public function testAdminCanGetAnyTenantsBooking(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR, 'admin-1');
        $booking = $this->makeBooking('tenant-B');

        /** @var BookingRepository&MockObject $bookingRepo */
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $service = $this->makeBookingService($bookingRepo);

        $result = $service->getBooking($admin, 'booking-1');
        $this->assertSame('booking-1', $result->getId());
    }

    // ─── Booking: LIST isolation ───────────────────────────────────────────

    public function testListBookingsForTenantInjectsTenantUserIdFilter(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');

        /** @var BookingRepository&MockObject $bookingRepo */
        $bookingRepo = $this->createMock(BookingRepository::class);

        $bookingRepo->expects($this->once())
            ->method('findByOrg')
            ->with(
                'org-1',
                $this->callback(fn($f) => ($f['tenant_user_id'] ?? null) === 'tenant-A'),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([]);
        $bookingRepo->method('countByOrg')->willReturn(0);

        $service = $this->makeBookingService($bookingRepo);
        $service->listBookings($tenant, [], 1, 20);
    }

    // ─── Payment: GET isolation ────────────────────────────────────────────

    public function testTenantCannotGetPaymentForAnotherTenantsBill(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');
        $payment = $this->makePayment('tenant-B'); // bill owned by tenant-B

        /** @var PaymentRepository&MockObject $paymentRepo */
        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->method('findByIdAndOrg')->willReturn($payment);

        $service = $this->makePaymentService($paymentRepo);

        $this->expectException(AccessDeniedException::class);
        $service->getPayment($tenant, 'payment-1');
    }

    public function testTenantCanGetOwnPayment(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');
        $payment = $this->makePayment('tenant-A');

        /** @var PaymentRepository&MockObject $paymentRepo */
        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->method('findByIdAndOrg')->willReturn($payment);

        $service = $this->makePaymentService($paymentRepo);

        $result = $service->getPayment($tenant, 'payment-1');
        $this->assertSame('payment-1', $result->getId());
    }

    public function testFinanceClerkCanGetAnyPayment(): void
    {
        $clerk = $this->makeUser(UserRole::FINANCE_CLERK, 'clerk-1');
        $payment = $this->makePayment('tenant-B');

        /** @var PaymentRepository&MockObject $paymentRepo */
        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->method('findByIdAndOrg')->willReturn($payment);

        $service = $this->makePaymentService($paymentRepo);

        $result = $service->getPayment($clerk, 'payment-1');
        $this->assertSame('payment-1', $result->getId());
    }

    // ─── Payment: LIST isolation ───────────────────────────────────────────

    public function testListPaymentsForTenantInjectsTenantUserIdFilter(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');

        /** @var PaymentRepository&MockObject $paymentRepo */
        $paymentRepo = $this->createMock(PaymentRepository::class);

        $paymentRepo->expects($this->once())
            ->method('findByOrg')
            ->with(
                'org-1',
                $this->callback(fn($f) => ($f['tenant_user_id'] ?? null) === 'tenant-A'),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([]);
        $paymentRepo->method('countByOrg')->willReturn(0);

        $service = $this->makePaymentService($paymentRepo);
        $service->listPayments($tenant, [], 1, 20);
    }

    public function testListPaymentsForFinanceClerkDoesNotInjectTenantFilter(): void
    {
        $clerk = $this->makeUser(UserRole::FINANCE_CLERK, 'clerk-1');

        /** @var PaymentRepository&MockObject $paymentRepo */
        $paymentRepo = $this->createMock(PaymentRepository::class);

        $paymentRepo->expects($this->once())
            ->method('findByOrg')
            ->with(
                'org-1',
                $this->callback(fn($f) => !isset($f['tenant_user_id'])),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([]);
        $paymentRepo->method('countByOrg')->willReturn(0);

        $service = $this->makePaymentService($paymentRepo);
        $service->listPayments($clerk, [], 1, 20);
    }

    // ─── Refund: GET isolation ─────────────────────────────────────────────

    public function testTenantCannotGetRefundForAnotherTenantsBill(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');
        $refund = $this->makeRefund('tenant-B');

        /** @var RefundRepository&MockObject $refundRepo */
        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByIdAndOrg')->willReturn($refund);

        $service = $this->makeRefundService($refundRepo);

        $this->expectException(AccessDeniedException::class);
        $service->getRefund($tenant, 'refund-1');
    }

    public function testTenantCanGetOwnRefund(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');
        $refund = $this->makeRefund('tenant-A');

        /** @var RefundRepository&MockObject $refundRepo */
        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByIdAndOrg')->willReturn($refund);

        $service = $this->makeRefundService($refundRepo);

        $result = $service->getRefund($tenant, 'refund-1');
        $this->assertSame('refund-1', $result->getId());
    }

    public function testFinanceClerkCanGetAnyRefund(): void
    {
        $clerk = $this->makeUser(UserRole::FINANCE_CLERK, 'clerk-1');
        $refund = $this->makeRefund('tenant-B');

        /** @var RefundRepository&MockObject $refundRepo */
        $refundRepo = $this->createMock(RefundRepository::class);
        $refundRepo->method('findByIdAndOrg')->willReturn($refund);

        $service = $this->makeRefundService($refundRepo);

        $result = $service->getRefund($clerk, 'refund-1');
        $this->assertSame('refund-1', $result->getId());
    }

    // ─── Refund: LIST isolation ────────────────────────────────────────────

    public function testListRefundsForTenantInjectsTenantUserIdFilter(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');

        /** @var RefundRepository&MockObject $refundRepo */
        $refundRepo = $this->createMock(RefundRepository::class);

        $refundRepo->expects($this->once())
            ->method('findByOrg')
            ->with(
                'org-1',
                $this->callback(fn($f) => ($f['tenant_user_id'] ?? null) === 'tenant-A'),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([]);
        $refundRepo->method('countByOrg')->willReturn(0);

        $service = $this->makeRefundService($refundRepo);
        $service->listRefunds($tenant, [], 1, 20);
    }

    public function testListRefundsForFinanceClerkDoesNotInjectTenantFilter(): void
    {
        $clerk = $this->makeUser(UserRole::FINANCE_CLERK, 'clerk-1');

        /** @var RefundRepository&MockObject $refundRepo */
        $refundRepo = $this->createMock(RefundRepository::class);

        $refundRepo->expects($this->once())
            ->method('findByOrg')
            ->with(
                'org-1',
                $this->callback(fn($f) => !isset($f['tenant_user_id'])),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([]);
        $refundRepo->method('countByOrg')->willReturn(0);

        $service = $this->makeRefundService($refundRepo);
        $service->listRefunds($clerk, [], 1, 20);
    }

    // ─── Cross-tenant: 404 on missing org record ───────────────────────────

    public function testGetBillFromDifferentOrgReturns404(): void
    {
        // Tenant tries to access a bill ID from a different org.
        // findByIdAndOrg scopes by orgId, so it returns null → EntityNotFoundException.
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');

        /** @var BillRepository&MockObject $billRepo */
        $billRepo = $this->createMock(BillRepository::class);
        $billRepo->method('findByIdAndOrg')->with('other-org-bill', 'org-1')->willReturn(null);

        $service = $this->makeBillingService($billRepo);

        $this->expectException(EntityNotFoundException::class);
        $service->getBill($tenant, 'other-org-bill');
    }

    public function testGetPaymentFromDifferentOrgReturns404(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');

        /** @var PaymentRepository&MockObject $paymentRepo */
        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->method('findByIdAndOrg')->with('foreign-payment', 'org-1')->willReturn(null);

        $service = $this->makePaymentService($paymentRepo);

        $this->expectException(EntityNotFoundException::class);
        $service->getPayment($tenant, 'foreign-payment');
    }

    // ─── Booking: cancel cross-tenant ─────────────────────────────────────

    public function testTenantCannotCancelAnotherTenantBooking(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'tenant-A');
        $booking = $this->makeBooking('tenant-B');

        // Mock status for cancel path
        $booking->method('getStatus')->willReturn(\App\Enum\BookingStatus::CONFIRMED);

        /** @var BookingRepository&MockObject $bookingRepo */
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('findByIdAndOrg')->willReturn($booking);

        $service = $this->makeBookingService($bookingRepo);

        $this->expectException(AccessDeniedException::class);
        $service->cancel($tenant, 'booking-1');
    }

    // ─── Service factory helpers ───────────────────────────────────────────

    private function makeBillingService(BillRepository&MockObject $billRepo): BillingService
    {
        /** @var PaymentRepository&MockObject $paymentRepo */
        $paymentRepo = $this->createMock(PaymentRepository::class);
        /** @var RefundRepository&MockObject $refundRepo */
        $refundRepo = $this->createMock(RefundRepository::class);
        /** @var PricingService&MockObject $pricing */
        $pricing = $this->createMock(PricingService::class);
        /** @var OrgTimeService&MockObject $time */
        $time = $this->createMock(OrgTimeService::class);
        $time->method('now')->willReturn(new \DateTimeImmutable());
        $time->method('getCurrentPeriod')->willReturn('2026-04');

        /** @var \App\Repository\SettingsRepository&MockObject $settingsRepo */
        $settingsRepo = $this->createMock(\App\Repository\SettingsRepository::class);

        return new BillingService(
            $billRepo,
            $paymentRepo,
            $refundRepo,
            $settingsRepo,
            $this->ledger,
            $pricing,
            $this->orgScope,
            $this->rbac,
            $this->audit,
            $this->notification,
            $time,
            $this->em,
        );
    }

    private function makeBookingService(BookingRepository&MockObject $bookingRepo): BookingService
    {
        /** @var \App\Repository\BookingEventRepository&MockObject $eventRepo */
        $eventRepo = $this->createMock(\App\Repository\BookingEventRepository::class);
        /** @var SettingsRepository&MockObject $settingsRepo */
        $settingsRepo = $this->createMock(SettingsRepository::class);
        /** @var BillingService&MockObject $billing */
        $billing = $this->createMock(BillingService::class);
        /** @var PricingService&MockObject $pricing */
        $pricing = $this->createMock(PricingService::class);
        /** @var \App\Service\BookingHoldService&MockObject $holdService */
        $holdService = $this->createMock(\App\Service\BookingHoldService::class);

        /** @var \App\Service\OrgTimeService&MockObject $orgTime */
        $orgTime = $this->createMock(\App\Service\OrgTimeService::class);
        $orgTime->method('now')->willReturn(new \DateTimeImmutable());

        return new BookingService(
            $bookingRepo,
            $eventRepo,
            $settingsRepo,
            $billing,
            $pricing,
            $holdService,
            $this->ledger,
            $this->audit,
            $this->notification,
            $this->orgScope,
            $this->rbac,
            $this->em,
            $orgTime,
        );
    }

    private function makePaymentService(PaymentRepository&MockObject $paymentRepo): PaymentService
    {
        /** @var BillRepository&MockObject $billRepo */
        $billRepo = $this->createMock(BillRepository::class);
        /** @var SettingsRepository&MockObject $settingsRepo */
        $settingsRepo = $this->createMock(SettingsRepository::class);
        /** @var \App\Service\BillingService&MockObject $billing */
        $billing = $this->createMock(BillingService::class);
        /** @var PaymentSignatureVerifier&MockObject $sigVerifier */
        $sigVerifier = $this->createMock(PaymentSignatureVerifier::class);

        return new PaymentService(
            $paymentRepo,
            $billRepo,
            $settingsRepo,
            $this->ledger,
            $billing,
            $sigVerifier,
            $this->orgScope,
            $this->rbac,
            $this->audit,
            $this->notification,
            $this->em,
        );
    }

    private function makeRefundService(RefundRepository&MockObject $refundRepo): RefundService
    {
        /** @var BillRepository&MockObject $billRepo */
        $billRepo = $this->createMock(BillRepository::class);
        /** @var PaymentRepository&MockObject $paymentRepo */
        $paymentRepo = $this->createMock(PaymentRepository::class);
        /** @var \App\Service\BillingService&MockObject $billing */
        $billing = $this->createMock(BillingService::class);

        return new RefundService(
            $refundRepo,
            $billRepo,
            $paymentRepo,
            $this->ledger,
            $billing,
            $this->orgScope,
            $this->rbac,
            $this->audit,
            $this->notification,
            $this->em,
        );
    }
}
