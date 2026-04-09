<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Booking;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    public function findByIdAndOrg(string $id, string $orgId): ?Booking
    {
        return $this->findOneBy(['id' => $id, 'organization' => $orgId]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return Booking[]
     */
    public function findByOrg(string $orgId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['status'])) {
            $qb->andWhere('b.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['inventory_item_id'])) {
            $qb->andWhere('b.inventoryItem = :itemId')
                ->setParameter('itemId', $filters['inventory_item_id']);
        }

        if (isset($filters['tenant_user_id'])) {
            $qb->andWhere('b.tenantUser = :tenantUser')
                ->setParameter('tenantUser', $filters['tenant_user_id']);
        }

        if (isset($filters['from'])) {
            $qb->andWhere('b.startAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('b.endAt <= :to')
                ->setParameter('to', $filters['to']);
        }

        $offset = ($page - 1) * $perPage;

        return $qb->orderBy('b.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Booking[]
     */
    public function findByTenant(string $userId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.tenantUser = :userId')
            ->setParameter('userId', $userId);

        if (isset($filters['status'])) {
            $qb->andWhere('b.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['from'])) {
            $qb->andWhere('b.startAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('b.endAt <= :to')
                ->setParameter('to', $filters['to']);
        }

        $offset = ($page - 1) * $perPage;

        return $qb->orderBy('b.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrg(string $orgId, array $filters): int
    {
        $qb = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['status'])) {
            $qb->andWhere('b.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['inventory_item_id'])) {
            $qb->andWhere('b.inventoryItem = :itemId')
                ->setParameter('itemId', $filters['inventory_item_id']);
        }

        if (isset($filters['tenant_user_id'])) {
            $qb->andWhere('b.tenantUser = :tenantUser')
                ->setParameter('tenantUser', $filters['tenant_user_id']);
        }

        if (isset($filters['from'])) {
            $qb->andWhere('b.startAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('b.endAt <= :to')
                ->setParameter('to', $filters['to']);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns the total number of UNITS booked (not record count) for overlapping active bookings.
     */
    public function sumActiveUnitsForItemInRange(
        string $itemId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): int {
        return (int) $this->createQueryBuilder('b')
            ->select('COALESCE(SUM(b.bookedUnits), 0)')
            ->where('b.status IN (:statuses)')
            ->andWhere('b.inventoryItem = :itemId')
            ->andWhere('b.startAt < :end')
            ->andWhere('b.endAt > :start')
            ->setParameter('statuses', ['confirmed', 'active'])
            ->setParameter('itemId', $itemId)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Booking[]
     */
    public function findActiveNoShows(int $gracePeriodMinutes): array
    {
        $cutoff = new \DateTimeImmutable(sprintf('-%d minutes', $gracePeriodMinutes));

        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.checkedInAt IS NULL')
            ->andWhere('b.startAt < :cutoff')
            ->setParameter('status', 'active')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find bookings eligible for recurring billing in the given org.
     * Period-level deduplication is handled by BillingService via BillRepository::findByBookingAndPeriod().
     *
     * @return Booking[]
     */
    public function findNeedingRecurringBill(string $orgId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.organization = :orgId')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('orgId', $orgId)
            ->setParameter('statuses', ['confirmed', 'active'])
            ->getQuery()
            ->getResult();
    }

    /**
     * Find bookings eligible for automated no-show evaluation.
     * Includes both CONFIRMED (never checked in — most common case) and ACTIVE
     * (checked in but system considers for grace period logic).
     *
     * @return Booking[]
     */
    public function findNoShowCandidates(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status IN (:statuses)')
            ->andWhere('b.checkedInAt IS NULL')
            ->setParameter('statuses', ['confirmed', 'active'])
            ->getQuery()
            ->getResult();
    }
}
