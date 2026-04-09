<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\PaymentValidationException;
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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FinancialValidationTest extends TestCase
{
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        $this->paymentService = new PaymentService(
            $this->createMock(PaymentRepository::class),
            $this->createMock(BillRepository::class),
            $this->createMock(SettingsRepository::class),
            $this->createMock(LedgerService::class),
            $this->createMock(BillingService::class),
            $this->createMock(PaymentSignatureVerifier::class),
            $this->createMock(OrganizationScope::class),
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(EntityManagerInterface::class),
        );
    }

    private function makeTenant(): User&MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('getRole')->willReturn(UserRole::TENANT);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        return $user;
    }

    public function testRejectZeroPaymentAmount(): void
    {
        $this->expectException(PaymentValidationException::class);
        $this->expectExceptionMessage('greater than zero');
        $this->paymentService->initiatePayment($this->makeTenant(), 'bill-1', '0.00', 'USD');
    }

    public function testRejectNegativePaymentAmount(): void
    {
        $this->expectException(PaymentValidationException::class);
        $this->expectExceptionMessage('greater than zero');
        $this->paymentService->initiatePayment($this->makeTenant(), 'bill-1', '-50.00', 'USD');
    }

    public function testRejectEmptyCurrency(): void
    {
        $this->expectException(PaymentValidationException::class);
        $this->expectExceptionMessage('Currency');
        $this->paymentService->initiatePayment($this->makeTenant(), 'bill-1', '100.00', '');
    }

    public function testRejectInvalidCurrencyLength(): void
    {
        $this->expectException(PaymentValidationException::class);
        $this->expectExceptionMessage('Currency');
        $this->paymentService->initiatePayment($this->makeTenant(), 'bill-1', '100.00', 'USDD');
    }

    public function testRejectNonNumericAmount(): void
    {
        $this->expectException(PaymentValidationException::class);
        $this->paymentService->initiatePayment($this->makeTenant(), 'bill-1', 'abc', 'USD');
    }
}
