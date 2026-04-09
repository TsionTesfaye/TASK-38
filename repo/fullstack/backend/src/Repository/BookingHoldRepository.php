<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookingHold;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookingHold>
 */
class BookingHoldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingHold::class);
    }

    public function findByIdAndOrg(string $id, string $orgId): ?BookingHold
    {
        return $this->findOneBy(['id' => $id, 'organization' => $orgId]);
    }

    public function findByRequestKey(string $userId, string $requestKey): ?BookingHold
    {
        return $this->findOneBy(['tenantUser' => $userId, 'requestKey' => $requestKey]);
    }

    /**
     * @return BookingHold[]
     */
    public function findExpiredActive(): array
    {
        return $this->createQueryBuilder('bh')
            ->where('bh.status = :status')
            ->andWhere('bh.expiresAt < :now')
            ->setParameter('status', 'active')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the total number of UNITS held (not record count) for overlapping
     * active holds that have not yet expired.
     */
    public function sumActiveUnitsForItemInRange(
        string $itemId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): int {
        return (int) $this->createQueryBuilder('bh')
            ->select('COALESCE(SUM(bh.heldUnits), 0)')
            ->where('bh.status = :status')
            ->andWhere('bh.expiresAt > :now')
            ->andWhere('bh.inventoryItem = :itemId')
            ->andWhere('bh.startAt < :end')
            ->andWhere('bh.endAt > :start')
            ->setParameter('status', 'active')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('itemId', $itemId)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
