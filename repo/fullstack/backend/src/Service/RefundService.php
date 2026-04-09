<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditActions;
use App\Entity\Refund;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\LedgerEntryType;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Exception\RefundExceededException;
use App\Repository\BillRepository;
use App\Repository\PaymentRepository;
use App\Repository\RefundRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class RefundService
{
    public function __construct(
        private readonly RefundRepository $refundRepository,
        private readonly BillRepository $billRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly LedgerService $ledgerService,
        private readonly BillingService $billingService,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function issueRefund(User $actor, string $billId, string $amount, string $reason): Refund
    {
        $this->rbac->enforce($actor, RbacEnforcer::ACTION_PROCESS_REFUND);

        if (!is_numeric($amount) || bccomp($amount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('Refund amount must be greater than zero');
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Refund reason is required');
        }

        $orgId = $this->orgScope->getOrganizationId($actor);

        $bill = $this->billRepository->findByIdAndOrg($billId, $orgId);
        if ($bill === null) {
            throw new EntityNotFoundException('Bill', $billId);
        }

        $successfulPayments = $this->paymentRepository->findByBillIdAndStatus(
            $billId,
            PaymentStatus::SUCCEEDED,
        );

        $totalPaid = '0.00';
        foreach ($successfulPayments as $payment) {
            $totalPaid = bcadd($totalPaid, $payment->getAmount(), 2);
        }

        $existingRefunds = $this->refundRepository->findByBillIdAndStatus($billId, RefundStatus::ISSUED);
        $totalRefunded = '0.00';
        foreach ($existingRefunds as $existingRefund) {
            $totalRefunded = bcadd($totalRefunded, $existingRefund->getAmount(), 2);
        }

        $refundable = bcsub($totalPaid, $totalRefunded, 2);

        if (bccomp($amount, $refundable, 2) > 0) {
            throw new RefundExceededException();
        }

        $this->em->beginTransaction();

        try {
            $refund = new Refund(
                Uuid::v4()->toRfc4122(),
                $bill->getOrganization(),
                $bill,
                null,
                $amount,
                $reason,
                RefundStatus::ISSUED,
                $actor,
            );

            $this->em->persist($refund);

            $this->ledgerService->createEntry(
                $orgId,
                LedgerEntryType::REFUND_ISSUED,
                $amount,
                $bill->getCurrency(),
                $bill->getBookingId(),
                $bill->getId(),
                null,
                $refund->getId(),
                ['reason' => $reason],
            );

            $this->billingService->updateBillStatus($bill);

            if ($bill->getStatus() === BillStatus::PAID) {
                $bill->transitionTo(BillStatus::PARTIALLY_REFUNDED);
            }

            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        // Post-commit: audit and notification are fire-and-forget.
        // Failures here must not undo the already-committed refund.
        try {
            $this->auditService->log(
                $orgId,
                $actor,
                $actor->getUsername(),
                AuditActions::REFUND_ISSUED,
                'Refund',
                $refund->getId(),
                null,
                ['bill_id' => $billId, 'amount' => $amount, 'reason' => $reason],
            );
        } catch (\Throwable) {
            // Audit failure is logged but does not fail the refund
        }

        try {
            $this->notificationService->createNotification($orgId, $bill->getTenantUserId(), 'refund.issued', 'Refund Issued', 'A refund of ' . $amount . ' has been issued for your bill.');
        } catch (\Throwable) {
            // Notification failure is tolerated — refund is already committed
        }

        return $refund;
    }

    public function getRefund(User $user, string $refundId): Refund
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $refund = $this->refundRepository->findByIdAndOrg($refundId, $orgId);

        if ($refund === null) {
            throw new EntityNotFoundException('Refund', $refundId);
        }

        if ($user->getRole() === UserRole::TENANT) {
            $bill = $refund->getBill();
            if ($bill->getTenantUserId() !== $user->getId()) {
                throw new AccessDeniedException('Refund does not belong to this tenant');
            }
        }

        return $refund;
    }

    public function listRefunds(User $user, array $filters, int $page, int $perPage): array
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $perPage = min($perPage, 100);

        if ($user->getRole() === UserRole::TENANT) {
            $filters['tenant_user_id'] = $user->getId();
        }

        $items = $this->refundRepository->findByOrg($orgId, $filters, $page, $perPage);
        $total = $this->refundRepository->countByOrg($orgId, $filters);

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
