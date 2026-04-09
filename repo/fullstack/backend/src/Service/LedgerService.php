<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\LedgerEntry;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\LedgerEntryType;
use App\Repository\LedgerEntryRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class LedgerService
{
    public function __construct(
        private readonly LedgerEntryRepository $ledgerRepo,
        private readonly EntityManagerInterface $em,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
    ) {}

    public function createEntry(
        string $orgId,
        LedgerEntryType $type,
        string $amount,
        string $currency,
        ?string $bookingId = null,
        ?string $billId = null,
        ?string $paymentId = null,
        ?string $refundId = null,
        ?array $metadata = null,
    ): LedgerEntry {
        $org = $this->em->getReference(Organization::class, $orgId);
        $booking = $bookingId ? $this->em->getReference(\App\Entity\Booking::class, $bookingId) : null;
        $bill = $billId ? $this->em->getReference(\App\Entity\Bill::class, $billId) : null;
        $payment = $paymentId ? $this->em->getReference(\App\Entity\Payment::class, $paymentId) : null;
        $refund = $refundId ? $this->em->getReference(\App\Entity\Refund::class, $refundId) : null;

        $entry = new LedgerEntry(
            Uuid::v4()->toRfc4122(), $org, $type, $amount, $currency,
            $booking, $bill, $payment, $refund, $metadata,
        );
        $this->em->persist($entry);
        return $entry;
    }

    public function getEntriesForBill(User $user, string $billId): array
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_FINANCE);
        $orgId = $this->orgScope->getOrganizationId($user);
        $bill = $this->em->getRepository(\App\Entity\Bill::class)->findOneBy(['id' => $billId, 'organization' => $orgId]);
        if ($bill === null) {
            throw new \App\Exception\EntityNotFoundException('Bill', $billId);
        }
        return $this->ledgerRepo->findByBillId($billId);
    }

    public function getEntriesForBooking(User $user, string $bookingId): array
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_FINANCE);
        $orgId = $this->orgScope->getOrganizationId($user);
        $booking = $this->em->getRepository(\App\Entity\Booking::class)->findOneBy(['id' => $bookingId, 'organization' => $orgId]);
        if ($booking === null) {
            throw new \App\Exception\EntityNotFoundException('Booking', $bookingId);
        }
        return $this->ledgerRepo->findByBookingId($bookingId);
    }

    /** Called within service-layer transactions only — org scope validated by calling service. Not accessible from controllers. */
    private function calculateBillBalanceInternal(string $billId): string
    {
        return $this->doCalculateBillBalance($billId);
    }

    public function calculateBillBalance(User $user, string $billId): string
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_FINANCE);
        $orgId = $this->orgScope->getOrganizationId($user);
        $bill = $this->em->getRepository(\App\Entity\Bill::class)->findOneBy(['id' => $billId, 'organization' => $orgId]);
        if ($bill === null) {
            throw new \App\Exception\EntityNotFoundException('Bill', $billId);
        }
        return $this->doCalculateBillBalance($billId);
    }

    private function doCalculateBillBalance(string $billId): string
    {
        $entries = $this->ledgerRepo->findByBillId($billId);
        $balance = '0.00';
        foreach ($entries as $entry) {
            switch ($entry->getEntryType()) {
                case LedgerEntryType::BILL_ISSUED:
                case LedgerEntryType::PENALTY_APPLIED:
                    $balance = bcadd($balance, $entry->getAmount(), 2);
                    break;
                case LedgerEntryType::PAYMENT_RECEIVED:
                    $balance = bcsub($balance, $entry->getAmount(), 2);
                    break;
                case LedgerEntryType::REFUND_ISSUED:
                    $balance = bcadd($balance, $entry->getAmount(), 2);
                    break;
                case LedgerEntryType::BILL_VOIDED:
                    $balance = '0.00';
                    break;
            }
        }
        return $balance;
    }

    public function listEntries(User $user, array $filters, int $page, int $perPage): array
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_FINANCE);
        $orgId = $this->orgScope->getOrganizationId($user);
        $perPage = min($perPage, 100);

        $items = $this->ledgerRepo->findByOrg($orgId, $filters, $page, $perPage);
        $total = $this->ledgerRepo->countByOrg($orgId, $filters);

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
