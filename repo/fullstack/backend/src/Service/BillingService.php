<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditActions;
use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\BookingStatus;
use App\Enum\LedgerEntryType;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\BillVoidException;
use App\Exception\EntityNotFoundException;
use App\Repository\BillRepository;
use App\Repository\PaymentRepository;
use App\Repository\RefundRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class BillingService
{
    public function __construct(
        private readonly BillRepository $billRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly RefundRepository $refundRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly LedgerService $ledgerService,
        private readonly PricingService $pricingService,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
        private readonly OrgTimeService $orgTimeService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function issueInitialBill(User $actor, Booking $booking): Bill
    {
        return $this->em->wrapInTransaction(function () use ($actor, $booking) {
        $bill = new Bill(
            Uuid::v4()->toRfc4122(),
            $booking->getOrganization(),
            $booking,
            $booking->getTenantUser(),
            BillType::INITIAL,
            $booking->getCurrency(),
            $booking->getFinalAmount(),
        );

        $this->em->persist($bill);

        $this->ledgerService->createEntry(
            $booking->getOrganizationId(),
            LedgerEntryType::BILL_ISSUED,
            $booking->getFinalAmount(),
            $booking->getCurrency(),
            $booking->getId(),
            $bill->getId(),
        );

        $this->em->flush();

        $this->auditService->log(
            $booking->getOrganizationId(),
            $actor,
            $actor->getUsername(),
            AuditActions::BILL_ISSUED,
            'Bill',
            $bill->getId(),
            null,
            ['type' => BillType::INITIAL->value, 'amount' => $booking->getFinalAmount()],
        );

        $this->notificationService->createNotification($booking->getOrganizationId(), $booking->getTenantUserId(), 'bill.issued', 'New Bill Issued', 'A new bill of ' . $booking->getFinalAmount() . ' ' . $booking->getCurrency() . ' has been issued.');

        return $bill;
        });
    }

    public function issueRecurringBill(Booking $booking): Bill
    {
        $orgId = $booking->getOrganizationId();
        $now = $this->orgTimeService->now($orgId);
        $periodMonth = $this->orgTimeService->getCurrentPeriod($orgId);

        $existing = $this->billRepository->findByBookingAndPeriod(
            $booking->getId(),
            $periodMonth,
            BillType::RECURRING,
        );

        if ($existing !== null) {
            return $existing;
        }

        return $this->em->wrapInTransaction(function () use ($booking, $now, $periodMonth) {
            $amount = $this->pricingService->calculateBookingAmount(
                $booking->getInventoryItemId(),
                $now->modify('first day of this month midnight'),
                $now->modify('first day of next month midnight'),
                $booking->getBookedUnits(),
            );

            $bill = new Bill(
                Uuid::v4()->toRfc4122(),
                $booking->getOrganization(),
                $booking,
                $booking->getTenantUser(),
                BillType::RECURRING,
                $booking->getCurrency(),
                $amount,
            );

            $this->em->persist($bill);

            $this->ledgerService->createEntry(
                $booking->getOrganizationId(),
                LedgerEntryType::BILL_ISSUED,
                $amount,
                $booking->getCurrency(),
                $booking->getId(),
                $bill->getId(),
                null,
                null,
                ['period' => $periodMonth],
            );

            return $bill;
        });
    }

    public function issueSupplementalBill(User $manager, string $bookingId, string $amount, string $reason): Bill
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_BILLING);

        if (!is_numeric($amount) || bccomp($amount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('Bill amount must be greater than zero');
        }

        $orgId = $this->orgScope->getOrganizationId($manager);

        $booking = $this->em->getRepository(Booking::class)->findOneBy([
            'id' => $bookingId,
            'organization' => $orgId,
        ]);

        if ($booking === null) {
            throw new EntityNotFoundException('Booking', $bookingId);
        }

        return $this->em->wrapInTransaction(function () use ($manager, $booking, $bookingId, $amount, $reason, $orgId) {
            $bill = new Bill(
                Uuid::v4()->toRfc4122(),
                $booking->getOrganization(),
                $booking,
                $booking->getTenantUser(),
                BillType::SUPPLEMENTAL,
                $booking->getCurrency(),
                $amount,
            );

            $this->em->persist($bill);

            $this->ledgerService->createEntry(
                $orgId,
                LedgerEntryType::BILL_ISSUED,
                $amount,
                $booking->getCurrency(),
                $bookingId,
                $bill->getId(),
                null,
                null,
                ['reason' => $reason],
            );

            $this->auditService->log(
                $orgId,
                $manager,
                $manager->getUsername(),
                AuditActions::BILL_ISSUED,
                'Bill',
                $bill->getId(),
                null,
                ['type' => BillType::SUPPLEMENTAL->value, 'amount' => $amount, 'reason' => $reason],
            );

            return $bill;
        });
    }

    public function issuePenaltyBill(User $actor, Booking $booking, string $amount, string $reason): Bill
    {
        return $this->em->wrapInTransaction(function () use ($actor, $booking, $amount, $reason) {
            $bill = new Bill(
                Uuid::v4()->toRfc4122(),
                $booking->getOrganization(),
                $booking,
                $booking->getTenantUser(),
                BillType::PENALTY,
                $booking->getCurrency(),
                $amount,
            );

            $this->em->persist($bill);

            $this->ledgerService->createEntry(
                $booking->getOrganizationId(),
                LedgerEntryType::PENALTY_APPLIED,
                $amount,
                $booking->getCurrency(),
                $booking->getId(),
                $bill->getId(),
                null,
                null,
                ['reason' => $reason],
            );

            $this->auditService->log(
                $booking->getOrganizationId(),
                $actor,
                $actor->getUsername(),
                AuditActions::BILL_ISSUED,
                'Bill',
                $bill->getId(),
                null,
                ['type' => BillType::PENALTY->value, 'amount' => $amount, 'reason' => $reason],
            );

            return $bill;
        });
    }

    public function voidBill(User $actor, string $billId): Bill
    {
        $this->rbac->enforce($actor, RbacEnforcer::ACTION_MANAGE_BILLING);
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

        $refunds = $this->refundRepository->findByBillIdAndStatus($billId, RefundStatus::ISSUED);
        $totalRefunded = '0.00';
        foreach ($refunds as $refund) {
            $totalRefunded = bcadd($totalRefunded, $refund->getAmount(), 2);
        }

        $unrefundedPayments = bcsub($totalPaid, $totalRefunded, 2);
        if (bccomp($unrefundedPayments, '0.00', 2) > 0) {
            throw new BillVoidException('Bill has unrefunded successful payments');
        }

        return $this->em->wrapInTransaction(function () use ($actor, $bill, $billId, $orgId) {
            $beforeStatus = $bill->getStatus();
            $bill->transitionTo(BillStatus::VOIDED);
            $bill->setOutstandingAmount('0.00');

            $this->ledgerService->createEntry(
                $orgId,
                LedgerEntryType::BILL_VOIDED,
                $bill->getOriginalAmount(),
                $bill->getCurrency(),
                $bill->getBookingId(),
                $bill->getId(),
            );

            $this->auditService->log(
                $orgId,
                $actor,
                $actor->getUsername(),
                AuditActions::BILL_VOIDED,
                'Bill',
                $billId,
                ['status' => $beforeStatus->value],
                ['status' => BillStatus::VOIDED->value],
            );

            return $bill;
        });
    }

    public function getBill(User $user, string $billId): Bill
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $bill = $this->billRepository->findByIdAndOrg($billId, $orgId);

        if ($bill === null) {
            throw new EntityNotFoundException('Bill', $billId);
        }

        if ($user->getRole() === UserRole::TENANT && $bill->getTenantUserId() !== $user->getId()) {
            throw new AccessDeniedException('Bill does not belong to this tenant');
        }

        return $bill;
    }

    public function listBills(User $user, array $filters, int $page, int $perPage): array
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $perPage = min($perPage, 100);

        if ($user->getRole() === UserRole::TENANT) {
            $filters['tenant_user_id'] = $user->getId();
        }

        $items = $this->billRepository->findByOrg($orgId, $filters, $page, $perPage);
        $total = $this->billRepository->countByOrg($orgId, $filters);

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

    public function generateRecurringBills(): int
    {
        $bookings = $this->em->getRepository(Booking::class)->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', BookingStatus::ACTIVE->value)
            ->getQuery()
            ->getResult();

        $count = 0;

        foreach ($bookings as $booking) {
            try {
                $orgId = $booking->getOrganizationId();

                // Recurring bills are generated monthly on the configured day
                // (default 1st) at the configured hour (default 09:00) in the
                // organisation's local timezone.
                //
                // The scheduler calls this method hourly. We only proceed when
                // the org-local date matches the billing day AND the local hour
                // is at or past the billing hour. This keeps the window tight:
                // billing fires once (on the correct day) and the period-based
                // dedup in findByBookingAndPeriod acts as a safety net.
                $settings = $this->settingsRepository->findByOrganizationId($orgId);
                $billingDay = $settings !== null ? $settings->getRecurringBillDay() : 1;
                $billingHour = $settings !== null ? $settings->getRecurringBillHour() : 9;

                $orgNow = $this->orgTimeService->now($orgId);
                $currentDay = (int) $orgNow->format('j');
                $currentHour = (int) $orgNow->format('G');

                if ($currentDay !== $billingDay || $currentHour < $billingHour) {
                    continue; // Not the billing window for this org
                }

                $periodMonth = $this->orgTimeService->getCurrentPeriod($orgId);

                $existing = $this->billRepository->findByBookingAndPeriod(
                    $booking->getId(),
                    $periodMonth,
                    BillType::RECURRING,
                );

                if ($existing !== null) {
                    continue;
                }

                $this->issueRecurringBill($booking);
                $count++;
            } catch (\Throwable) {
                // Per-booking isolation: failure on one booking does not halt others
                continue;
            }
        }

        return $count;
    }

    public function updateBillStatus(Bill $bill): void
    {
        if ($bill->getStatus() === BillStatus::VOIDED) {
            return;
        }

        $successfulPayments = $this->paymentRepository->findByBillIdAndStatus(
            $bill->getId(),
            PaymentStatus::SUCCEEDED,
        );

        $totalPaid = '0.00';
        foreach ($successfulPayments as $payment) {
            $totalPaid = bcadd($totalPaid, $payment->getAmount(), 2);
        }

        $refunds = $this->refundRepository->findByBillIdAndStatus($bill->getId(), RefundStatus::ISSUED);
        $totalRefunded = '0.00';
        foreach ($refunds as $refund) {
            $totalRefunded = bcadd($totalRefunded, $refund->getAmount(), 2);
        }

        $netPaid = bcsub($totalPaid, $totalRefunded, 2);
        $outstanding = bcsub($bill->getOriginalAmount(), $netPaid, 2);

        if (bccomp($outstanding, '0.00', 2) < 0) {
            $outstanding = '0.00';
        }

        $bill->setOutstandingAmount($outstanding);

        if (bccomp($outstanding, '0.00', 2) === 0 && bccomp($totalRefunded, '0.00', 2) === 0) {
            if ($bill->getStatus() !== BillStatus::PAID) {
                $bill->transitionTo(BillStatus::PAID);
            }
        } elseif (bccomp($totalRefunded, '0.00', 2) > 0 && $bill->getStatus() === BillStatus::PAID) {
            $bill->transitionTo(BillStatus::PARTIALLY_REFUNDED);
        } elseif (bccomp($outstanding, '0.00', 2) === 0 && bccomp($totalRefunded, '0.00', 2) > 0) {
            if ($bill->getStatus() !== BillStatus::PAID && $bill->getStatus() !== BillStatus::PARTIALLY_REFUNDED) {
                $bill->transitionTo(BillStatus::PAID);
            }
        } elseif (bccomp($outstanding, '0.00', 2) > 0 && bccomp($netPaid, '0.00', 2) > 0) {
            if ($bill->getStatus() === BillStatus::OPEN) {
                $bill->transitionTo(BillStatus::PARTIALLY_PAID);
            }
        }

        // Do NOT flush here — callers (PaymentService, RefundService) control the transaction boundary.
        // Entity changes are tracked by Doctrine and will be flushed when the caller commits.
    }
}
