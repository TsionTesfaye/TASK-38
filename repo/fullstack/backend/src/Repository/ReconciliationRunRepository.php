<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReconciliationRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReconciliationRun>
 */
class ReconciliationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReconciliationRun::class);
    }

    public function findByOrgAndDate(string $orgId, \DateTimeImmutable $date): ?ReconciliationRun
    {
        return $this->findOneBy(['organization' => $orgId, 'runDate' => $date]);
    }

    public function findByIdAndOrg(string $id, string $orgId): ?ReconciliationRun
    {
        return $this->findOneBy(['id' => $id, 'organization' => $orgId]);
    }

    /**
     * @return ReconciliationRun[]
     */
    public function findByOrg(string $orgId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        return $this->createQueryBuilder('rr')
            ->where('rr.organization = :orgId')
            ->setParameter('orgId', $orgId)
            ->orderBy('rr.runDate', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrg(string $orgId): int
    {
        return (int) $this->createQueryBuilder('rr')
            ->select('COUNT(rr.id)')
            ->where('rr.organization = :orgId')
            ->setParameter('orgId', $orgId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
