<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InventoryPricing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InventoryPricing>
 */
class InventoryPricingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryPricing::class);
    }

    public function findActiveForItem(string $itemId, \DateTimeImmutable $at): ?InventoryPricing
    {
        return $this->createQueryBuilder('p')
            ->where('p.inventoryItem = :itemId')
            ->andWhere('p.effectiveFrom <= :at')
            ->andWhere('p.effectiveTo IS NULL OR p.effectiveTo > :at')
            ->setParameter('itemId', $itemId)
            ->setParameter('at', $at)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return InventoryPricing[]
     */
    public function findByItemId(string $itemId): array
    {
        return $this->findBy(['inventoryItem' => $itemId]);
    }

    public function checkOverlap(string $itemId, \DateTimeImmutable $from, ?\DateTimeImmutable $to): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.inventoryItem = :itemId')
            ->andWhere('p.effectiveFrom < :to OR :toIsNull = true')
            ->andWhere('p.effectiveTo IS NULL OR p.effectiveTo > :from')
            ->setParameter('itemId', $itemId)
            ->setParameter('from', $from);

        if ($to !== null) {
            $qb->setParameter('to', $to)
                ->setParameter('toIsNull', false);
        } else {
            $qb->setParameter('to', $from)
                ->setParameter('toIsNull', true);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
