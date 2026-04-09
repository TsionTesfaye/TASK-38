<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Terminal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Terminal>
 */
class TerminalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Terminal::class);
    }

    public function findByIdAndOrg(string $id, string $orgId): ?Terminal
    {
        return $this->findOneBy(['id' => $id, 'organization' => $orgId]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return Terminal[]
     */
    public function findByOrg(string $orgId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['is_active'])) {
            $qb->andWhere('t.isActive = :status')
                ->setParameter('status', $filters['is_active']);
        }

        if (isset($filters['location_group'])) {
            $qb->andWhere('t.locationGroup = :locationGroup')
                ->setParameter('locationGroup', $filters['location_group']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere('t.displayName LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        $offset = ($page - 1) * $perPage;

        return $qb->orderBy('t.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrg(string $orgId, array $filters): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['is_active'])) {
            $qb->andWhere('t.isActive = :status')
                ->setParameter('status', $filters['is_active']);
        }

        if (isset($filters['location_group'])) {
            $qb->andWhere('t.locationGroup = :locationGroup')
                ->setParameter('locationGroup', $filters['location_group']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere('t.displayName LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
