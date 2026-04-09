<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IdempotencyKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IdempotencyKey>
 */
class IdempotencyKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdempotencyKey::class);
    }

    public function findByUserAndKey(string $userId, string $requestKey): ?IdempotencyKey
    {
        return $this->findOneBy(['user' => $userId, 'requestKey' => $requestKey]);
    }

    public function deleteExpired(): int
    {
        return (int) $this->createQueryBuilder('ik')
            ->delete()
            ->where('ik.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
