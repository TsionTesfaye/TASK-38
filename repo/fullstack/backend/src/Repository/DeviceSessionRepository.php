<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeviceSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceSession>
 */
class DeviceSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceSession::class);
    }

    /**
     * @return DeviceSession[]
     */
    public function findActiveByUserId(string $userId): array
    {
        return $this->createQueryBuilder('ds')
            ->where('ds.user = :userId')
            ->andWhere('ds.revokedAt IS NULL')
            ->andWhere('ds.expiresAt > :now')
            ->setParameter('userId', $userId)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    public function countActiveByUserId(string $userId): int
    {
        return (int) $this->createQueryBuilder('ds')
            ->select('COUNT(ds.id)')
            ->where('ds.user = :userId')
            ->andWhere('ds.revokedAt IS NULL')
            ->andWhere('ds.expiresAt > :now')
            ->setParameter('userId', $userId)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOldestActiveByUserId(string $userId): ?DeviceSession
    {
        return $this->createQueryBuilder('ds')
            ->where('ds.user = :userId')
            ->andWhere('ds.revokedAt IS NULL')
            ->andWhere('ds.expiresAt > :now')
            ->setParameter('userId', $userId)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('ds.issuedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function revokeAllByUserId(string $userId): int
    {
        return (int) $this->createQueryBuilder('ds')
            ->update()
            ->set('ds.revokedAt', ':now')
            ->where('ds.user = :userId')
            ->andWhere('ds.revokedAt IS NULL')
            ->setParameter('userId', $userId)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    /**
     * Revoke the oldest active sessions to bring the count below the given cap.
     *
     * CONCURRENCY SAFETY: Acquires a pessimistic write lock (SELECT ... FOR UPDATE)
     * on the user's active sessions. This serializes concurrent login attempts
     * for the same user, guaranteeing that at most $maxAllowed sessions exist
     * after the new session is created.
     *
     * MUST be called inside an active transaction.
     *
     * @return int Number of sessions revoked
     */
    public function revokeExcessByUserId(string $userId, int $maxAllowed): int
    {
        // Acquire pessimistic write lock on ALL active sessions for this user.
        // This blocks any concurrent login from reading stale counts.
        $active = $this->createQueryBuilder('ds')
            ->where('ds.user = :userId')
            ->andWhere('ds.revokedAt IS NULL')
            ->andWhere('ds.expiresAt > :now')
            ->setParameter('userId', $userId)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('ds.issuedAt', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        $excess = count($active) - $maxAllowed + 1; // +1 to make room for the new session
        if ($excess <= 0) {
            return 0;
        }

        $revoked = 0;
        for ($i = 0; $i < $excess; $i++) {
            $active[$i]->revoke();
            $revoked++;
        }

        return $revoked;
    }

    public function findByRefreshTokenHash(string $hash): ?DeviceSession
    {
        return $this->findOneBy(['refreshTokenHash' => $hash]);
    }
}
