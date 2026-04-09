<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function findByIdAndOrg(string $id, string $orgId): ?User
    {
        return $this->findOneBy(['id' => $id, 'organization' => $orgId]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return User[]
     */
    public function findByOrganizationId(string $orgId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['role'])) {
            $qb->andWhere('u.role = :role')
                ->setParameter('role', $filters['role']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere('u.username LIKE :search OR u.displayName LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['is_active'])) {
            $qb->andWhere('u.isActive = :active')
                ->setParameter('active', $filters['is_active']);
        }

        $offset = ($page - 1) * $perPage;

        return $qb->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrganizationId(string $orgId, array $filters): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['role'])) {
            $qb->andWhere('u.role = :role')
                ->setParameter('role', $filters['role']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere('u.username LIKE :search OR u.displayName LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['is_active'])) {
            $qb->andWhere('u.isActive = :active')
                ->setParameter('active', $filters['is_active']);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countAdminsInSystem(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.role = :role')
            ->setParameter('role', UserRole::ADMINISTRATOR->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns all distinct organisation IDs present in the users table.
     * Used by CreateBackupCommand to back up all organisations.
     *
     * @return string[]
     */
    public function findDistinctOrganizationIds(): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('DISTINCT IDENTITY(u.organization) AS orgId')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'orgId');
    }
}
