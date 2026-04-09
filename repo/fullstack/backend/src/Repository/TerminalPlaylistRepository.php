<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TerminalPlaylist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TerminalPlaylist>
 */
class TerminalPlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TerminalPlaylist::class);
    }

    /**
     * @return TerminalPlaylist[]
     */
    public function findByOrgAndLocation(string $orgId, string $locationGroup, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        return $this->createQueryBuilder('tp')
            ->where('tp.organization = :orgId')
            ->andWhere('tp.locationGroup = :locationGroup')
            ->setParameter('orgId', $orgId)
            ->setParameter('locationGroup', $locationGroup)
            ->orderBy('tp.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrgAndLocation(string $orgId, string $locationGroup): int
    {
        return (int) $this->createQueryBuilder('tp')
            ->select('COUNT(tp.id)')
            ->where('tp.organization = :orgId')
            ->andWhere('tp.locationGroup = :locationGroup')
            ->setParameter('orgId', $orgId)
            ->setParameter('locationGroup', $locationGroup)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
