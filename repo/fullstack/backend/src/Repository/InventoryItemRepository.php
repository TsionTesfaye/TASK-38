<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InventoryItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InventoryItem>
 */
class InventoryItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryItem::class);
    }

    public function findByIdAndOrg(string $id, string $orgId): ?InventoryItem
    {
        return $this->findOneBy(['id' => $id, 'organization' => $orgId]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return InventoryItem[]
     */
    public function findByOrganizationId(string $orgId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['asset_type'])) {
            $qb->andWhere('i.assetType = :category')
                ->setParameter('category', $filters['asset_type']);
        }

        if (isset($filters['is_active'])) {
            $qb->andWhere('i.isActive = :status')
                ->setParameter('status', $filters['is_active']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere('i.name LIKE :search OR i.assetCode LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        $offset = ($page - 1) * $perPage;

        return $qb->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrganizationId(string $orgId, array $filters): int
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['asset_type'])) {
            $qb->andWhere('i.assetType = :category')
                ->setParameter('category', $filters['asset_type']);
        }

        if (isset($filters['is_active'])) {
            $qb->andWhere('i.isActive = :status')
                ->setParameter('status', $filters['is_active']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere('i.name LIKE :search OR i.assetCode LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findByOrgAndAssetCode(string $orgId, string $code): ?InventoryItem
    {
        return $this->findOneBy(['organization' => $orgId, 'assetCode' => $code]);
    }
}
