<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditActions;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\LedgerEntryType;
use App\Enum\PaymentStatus;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\CurrencyMismatchException;
use App\Exception\EntityNotFoundException;
use App\Exception\PaymentValidationException;
use App\Repository\BillRepository;
use App\Repository\PaymentRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\PaymentSignatureVerifier;
use App\Security\RbacEnforcer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class PaymentService
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly BillRepository $billRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly LedgerService $ledgerService,
        private readonly BillingService $billingService,
        private readonly PaymentSignatureVerifier $signatureVerifier,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function initiatePayment(User $tenant, string $billId, string $amount, string $currency): Payment
    {
        if ($tenant->getRole() !== UserRole::TENANT) {
            throw new AccessDeniedException('Only tenants can initiate payments');
        }

        if (!is_numeric($amount) || bccomp($amount, '0.00', 2) <= 0) {
            throw new PaymentValidationException('Payment amount must be greater than zero');
        }

        if (trim($currency) === '' || strlen($currency) !== 3) {
            throw new PaymentValidationException('Currency must be a valid 3-character code');
        }

        $orgId = $this->orgScope->getOrganizationId($tenant);

        // Lock the bill row to prevent concurrent payments from exceeding outstanding amount
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // Acquire exclusive lock on the bill
            $row = $conn->fetchAssociative(
                'SELECT id, status, outstanding_amount, currency, tenant_user_id FROM bills WHERE id = ? AND organization_id = ? FOR UPDATE',
                [$billId, $orgId],
            );

            if ($row === false) {
                $conn->rollBack();
                throw new EntityNotFoundException('Bill', $billId);
            }

            if ($row['tenant_user_id'] !== $tenant->getId()) {
                $conn->rollBack();
                throw new AccessDeniedException('Bill does not belong to this tenant');
            }

            $billStatus = $row['status'];
            if ($billStatus !== 'open' && $billStatus !== 'partially_paid') {
                $conn->rollBack();
                throw new PaymentValidationException('Bill is not in a payable status');
            }

            if ($currency !== $row['currency']) {
                $conn->rollBack();
                throw new CurrencyMismatchException();
            }

            $outstanding = $row['outstanding_amount'];

            // Deduct PENDING payments already in flight to prevent sequential overpayment.
            // This query runs inside the bill FOR UPDATE lock, so no new payments can be
            // inserted concurrently for this bill.
            $pendingTotal = $conn->fetchOne(
                'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE bill_id = ? AND status = ?',
                [$billId, 'pending'],
            );
            $effectiveOutstanding = bcsub($outstanding, (string) $pendingTotal, 2);

            if (bccomp($effectiveOutstanding, '0.00', 2) <= 0) {
                $conn->rollBack();
                throw new PaymentValidationException('A payment is already pending for this bill');
            }

            $settings = $this->settingsRepository->findByOrganizationId($orgId);
            $allowPartial = $settings !== null ? $settings->getAllowPartialPayments() : false;

            if (!$allowPartial) {
                if (bccomp($amount, $effectiveOutstanding, 2) !== 0) {
                    $conn->rollBack();
                    throw new PaymentValidationException('Partial payments are not allowed; amount must equal outstanding balance');
                }
            } else {
                if (bccomp($amount, $effectiveOutstanding, 2) > 0) {
                    $conn->rollBack();
                    throw new PaymentValidationException('Payment amount exceeds available balance');
                }
            }

            // Refresh the bill entity inside the lock scope
            $bill = $this->billRepository->findByIdAndOrg($billId, $orgId);
            $requestId = Uuid::v4()->toRfc4122();

            $payment = new Payment(
                Uuid::v4()->toRfc4122(),
                $bill->getOrganization(),
                $bill,
                $requestId,
                $currency,
                $amount,
            );

            $this->em->persist($payment);
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        $this->auditService->log(
            $orgId,
            $tenant,
            $tenant->getUsername(),
            AuditActions::PAYMENT_INITIATED,
            'Payment',
            $payment->getId(),
            null,
            ['bill_id' => $billId, 'amount' => $amount, 'currency' => $currency, 'request_id' => $requestId],
        );

        return $payment;
    }

    public function processCallback(string $requestId, string $signature, array $payload): Payment
    {
        $this->em->beginTransaction();

        try {
            // Row-level lock to prevent concurrent callback processing
            $conn = $this->em->getConnection();
            $row = $conn->fetchAssociative(
                'SELECT id, status FROM payments WHERE request_id = ? FOR UPDATE',
                [$requestId],
            );

            if ($row === false) {
                $this->em->rollback();
                throw new EntityNotFoundException('Payment', $requestId);
            }

            // Idempotency: if already terminal, return existing result without reprocessing
            $currentStatus = PaymentStatus::from($row['status']);
            if ($currentStatus->isTerminal()) {
                $this->em->rollback();
                $payment = $this->paymentRepository->findByRequestId($requestId);
                return $payment;
            }

            $payment = $this->paymentRepository->findByRequestId($requestId);

            if (!$this->signatureVerifier->verifySignature($signature, $payload)) {
                $this->em->rollback();
                throw new PaymentValidationException('Invalid payment signature');
            }

            $payment->setSignatureVerified(true);
            $payment->setRawCallbackPayloadJson($payload);

            $callbackAmount = (string) ($payload['amount'] ?? '0.00');
            $callbackCurrency = (string) ($payload['currency'] ?? '');

            if (bccomp($callbackAmount, $payment->getAmount(), 2) !== 0) {
                $this->em->rollback();
                throw new PaymentValidationException('Callback amount does not match payment amount');
            }

            if ($callbackCurrency !== $payment->getCurrency()) {
                $this->em->rollback();
                throw new PaymentValidationException('Callback currency does not match payment currency');
            }

            $callbackStatus = (string) ($payload['status'] ?? '');
            $externalReference = (string) ($payload['external_reference'] ?? '');

            if ($externalReference !== '') {
                $payment->setExternalReference($externalReference);
            }

            if ($callbackStatus === 'succeeded' || $callbackStatus === 'success') {
                $bill = $payment->getBill();

                // Lock the bill row to prevent concurrent void/callback races.
                $billRow = $conn->fetchAssociative(
                    'SELECT id, status FROM bills WHERE id = ? FOR UPDATE',
                    [$bill->getId()],
                );

                // Reject payment if the bill is not in a payable status.
                $billStatus = $billRow !== false ? $billRow['status'] : null;
                if ($billStatus !== BillStatus::OPEN->value && $billStatus !== BillStatus::PARTIALLY_PAID->value) {
                    $payment->transitionTo(PaymentStatus::REJECTED);
                    $this->em->flush();
                    $this->em->commit();

                    return $payment;
                }

                // Re-check: would this payment cause overpayment?
                // Sum all already-SUCCEEDED payments for this bill.
                $alreadyPaid = (string) $conn->fetchOne(
                    'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE bill_id = ? AND status = ?',
                    [$bill->getId(), 'succeeded'],
                );
                $alreadyRefunded = (string) $conn->fetchOne(
                    'SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE bill_id = ? AND status = ?',
                    [$bill->getId(), 'issued'],
                );
                $netPaid = bcsub($alreadyPaid, $alreadyRefunded, 2);
                $wouldBePaid = bcadd($netPaid, $payment->getAmount(), 2);

                if (bccomp($wouldBePaid, $bill->getOriginalAmount(), 2) > 0) {
                    // This payment would exceed the bill total — reject it
                    $payment->transitionTo(PaymentStatus::REJECTED);
                    $this->em->flush();
                    $this->em->commit();

                    return $payment;
                }

                $payment->transitionTo(PaymentStatus::SUCCEEDED);

                // Flush the payment status before updateBillStatus queries for
                // succeeded payments. Without this, the DQL query in
                // updateBillStatus may not see the in-memory status change.
                $this->em->flush();

                $this->billingService->updateBillStatus($bill);

                $this->ledgerService->createEntry(
                    $payment->getOrganizationId(),
                    LedgerEntryType::PAYMENT_RECEIVED,
                    $payment->getAmount(),
                    $payment->getCurrency(),
                    $bill->getBookingId(),
                    $bill->getId(),
                    $payment->getId(),
                );
            } elseif ($callbackStatus === 'rejected') {
                $payment->transitionTo(PaymentStatus::REJECTED);
            } else {
                $payment->transitionTo(PaymentStatus::FAILED);
            }

            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            throw $e;
        }

        // Post-commit: audit and notification are fire-and-forget
        try {
            $this->auditService->log(
                $payment->getOrganizationId(),
                null,
                'payment_callback',
                AuditActions::PAYMENT_CALLBACK_PROCESSED,
                'Payment',
                $payment->getId(),
                ['status' => PaymentStatus::PENDING->value],
                ['status' => $payment->getStatus()->value],
            );
        } catch (\Throwable) {
            // Audit failure does not undo committed payment
        }

        try {
            if ($payment->getStatus() === PaymentStatus::SUCCEEDED) {
                $bill = $payment->getBill();
                $this->notificationService->createNotification($payment->getOrganizationId(), $bill->getTenantUserId(), 'payment.received', 'Payment Received', 'Your payment of ' . $payment->getAmount() . ' ' . $payment->getCurrency() . ' has been received.');
            }
        } catch (\Throwable) {
            // Notification failure is tolerated — payment is already committed
        }

        return $payment;
    }

    public function getPayment(User $user, string $paymentId): Payment
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $payment = $this->paymentRepository->findByIdAndOrg($paymentId, $orgId);

        if ($payment === null) {
            throw new EntityNotFoundException('Payment', $paymentId);
        }

        if ($user->getRole() === UserRole::TENANT) {
            $bill = $payment->getBill();
            if ($bill->getTenantUserId() !== $user->getId()) {
                throw new AccessDeniedException('Payment does not belong to this tenant');
            }
        }

        return $payment;
    }

    public function listPayments(User $user, array $filters, int $page, int $perPage): array
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $perPage = min($perPage, 100);

        if ($user->getRole() === UserRole::TENANT) {
            $filters['tenant_user_id'] = $user->getId();
        }

        $items = $this->paymentRepository->findByOrg($orgId, $filters, $page, $perPage);
        $total = $this->paymentRepository->countByOrg($orgId, $filters);

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_next' => ($page * $perPage) < $total,
            ],
        ];
    }
}
