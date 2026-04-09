<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @param array<string, mixed> $filters
     * @return AuditLog[]
     */
    public function findByOrg(string $orgId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('al')
            ->where('al.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['action_code'])) {
            $qb->andWhere('al.actionCode = :action')
                ->setParameter('action', $filters['action_code']);
        }

        if (isset($filters['actor_user_id'])) {
            $qb->andWhere('al.actorUser = :userId')
                ->setParameter('userId', $filters['actor_user_id']);
        }

        if (isset($filters['object_type'])) {
            $qb->andWhere('al.objectType = :entityType')
                ->setParameter('entityType', $filters['object_type']);
        }

        if (isset($filters['object_id'])) {
            $qb->andWhere('al.objectId = :entityId')
                ->setParameter('entityId', $filters['object_id']);
        }

        if (isset($filters['from'])) {
            $qb->andWhere('al.createdAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('al.createdAt <= :to')
                ->setParameter('to', $filters['to']);
        }

        $offset = ($page - 1) * $perPage;

        return $qb->orderBy('al.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrg(string $orgId, array $filters): int
    {
        $qb = $this->createQueryBuilder('al')
            ->select('COUNT(al.id)')
            ->where('al.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['action_code'])) {
            $qb->andWhere('al.actionCode = :action')
                ->setParameter('action', $filters['action_code']);
        }

        if (isset($filters['actor_user_id'])) {
            $qb->andWhere('al.actorUser = :userId')
                ->setParameter('userId', $filters['actor_user_id']);
        }

        if (isset($filters['object_type'])) {
            $qb->andWhere('al.objectType = :entityType')
                ->setParameter('entityType', $filters['object_type']);
        }

        if (isset($filters['object_id'])) {
            $qb->andWhere('al.objectId = :entityId')
                ->setParameter('entityId', $filters['object_id']);
        }

        if (isset($filters['from'])) {
            $qb->andWhere('al.createdAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('al.createdAt <= :to')
                ->setParameter('to', $filters['to']);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
