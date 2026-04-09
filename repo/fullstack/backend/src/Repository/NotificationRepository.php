<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @return Notification[]
     */
    public function findPendingDue(): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.status = :status')
            ->andWhere('n.scheduledFor <= :now')
            ->setParameter('status', 'pending')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    public function countPendingDueByOrg(string $orgId): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.status = :status')
            ->andWhere('n.scheduledFor <= :now')
            ->andWhere('n.organization = :orgId')
            ->setParameter('status', 'pending')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('orgId', $orgId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Notification[]
     */
    public function findByUser(string $userId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        return $this->createQueryBuilder('n')
            ->where('n.user = :userId')
            ->andWhere('n.status IN (:statuses)')
            ->setParameter('userId', $userId)
            ->setParameter('statuses', ['delivered', 'read'])
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByUser(string $userId): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :userId')
            ->andWhere('n.status IN (:statuses)')
            ->setParameter('userId', $userId)
            ->setParameter('statuses', ['delivered', 'read'])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByIdAndUser(string $id, string $userId): ?Notification
    {
        return $this->findOneBy(['id' => $id, 'user' => $userId]);
    }
}
