<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LedgerEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LedgerEntry>
 */
class LedgerEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LedgerEntry::class);
    }

    /**
     * @return LedgerEntry[]
     */
    public function findByBillId(string $billId): array
    {
        return $this->findBy(['bill' => $billId]);
    }

    /**
     * @return LedgerEntry[]
     */
    public function findByBookingId(string $bookingId): array
    {
        return $this->findBy(['booking' => $bookingId]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return LedgerEntry[]
     */
    public function findByOrg(string $orgId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('le')
            ->where('le.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['entry_type'])) {
            $qb->andWhere('le.entryType = :type')
                ->setParameter('type', $filters['entry_type']);
        }

        if (isset($filters['booking_id'])) {
            $qb->andWhere('le.booking = :bookingId')
                ->setParameter('bookingId', $filters['booking_id']);
        }

        if (isset($filters['bill_id'])) {
            $qb->andWhere('le.bill = :billId')
                ->setParameter('billId', $filters['bill_id']);
        }

        if (isset($filters['from'])) {
            $qb->andWhere('le.occurredAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('le.occurredAt <= :to')
                ->setParameter('to', $filters['to']);
        }

        $offset = ($page - 1) * $perPage;

        return $qb->orderBy('le.occurredAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrg(string $orgId, array $filters): int
    {
        $qb = $this->createQueryBuilder('le')
            ->select('COUNT(le.id)')
            ->where('le.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['entry_type'])) {
            $qb->andWhere('le.entryType = :type')
                ->setParameter('type', $filters['entry_type']);
        }

        if (isset($filters['booking_id'])) {
            $qb->andWhere('le.booking = :bookingId')
                ->setParameter('bookingId', $filters['booking_id']);
        }

        if (isset($filters['bill_id'])) {
            $qb->andWhere('le.bill = :billId')
                ->setParameter('billId', $filters['bill_id']);
        }

        if (isset($filters['from'])) {
            $qb->andWhere('le.occurredAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('le.occurredAt <= :to')
                ->setParameter('to', $filters['to']);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
