<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TerminalPackageTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TerminalPackageTransfer>
 */
class TerminalPackageTransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TerminalPackageTransfer::class);
    }

    public function findByIdAndOrg(string $id, string $orgId): ?TerminalPackageTransfer
    {
        return $this->findOneBy(['id' => $id, 'organization' => $orgId]);
    }

    /**
     * @return TerminalPackageTransfer[]
     */
    public function findByTerminal(string $terminalId): array
    {
        return $this->findBy(['terminal' => $terminalId]);
    }

    /**
     * @return TerminalPackageTransfer[]
     */
    public function findIncomplete(): array
    {
        return $this->createQueryBuilder('tpt')
            ->where('tpt.completedAt IS NULL')
            ->getQuery()
            ->getResult();
    }

    public function countIncompleteByOrg(string $orgId): int
    {
        return (int) $this->createQueryBuilder('tpt')
            ->select('COUNT(tpt.id)')
            ->where('tpt.completedAt IS NULL')
            ->andWhere('tpt.organization = :orgId')
            ->setParameter('orgId', $orgId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
