<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    public function findByCode(string $code): ?Organization
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @return Organization[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }
}
