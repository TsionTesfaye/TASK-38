<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use App\Enum\PaymentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function findByRequestId(string $requestId): ?Payment
    {
        return $this->findOneBy(['requestId' => $requestId]);
    }

    /**
     * @return Payment[]
     */
    public function findByBillId(string $billId): array
    {
        return $this->findBy(['bill' => $billId]);
    }

    public function findByIdAndOrg(string $id, string $orgId): ?Payment
    {
        return $this->findOneBy(['id' => $id, 'organization' => $orgId]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return Payment[]
     */
    public function findByOrg(string $orgId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['status'])) {
            $qb->andWhere('p.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['bill_id'])) {
            $qb->andWhere('p.bill = :billId')
                ->setParameter('billId', $filters['bill_id']);
        }


        // Tenant isolation: Payment has no direct tenantUser field — scope via bill.
        // This filter is ALWAYS set by the service layer for TENANT-role users.
        if (isset($filters['tenant_user_id'])) {
            $qb->join('p.bill', 'pb')
                ->andWhere('pb.tenantUser = :tenantUser')
                ->setParameter('tenantUser', $filters['tenant_user_id']);
        }

        $offset = ($page - 1) * $perPage;

        return $qb->orderBy('p.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrg(string $orgId, array $filters): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['status'])) {
            $qb->andWhere('p.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['bill_id'])) {
            $qb->andWhere('p.bill = :billId')
                ->setParameter('billId', $filters['bill_id']);
        }


        // Tenant isolation: scope via bill when tenant_user_id is present.
        if (isset($filters['tenant_user_id'])) {
            $qb->join('p.bill', 'pb')
                ->andWhere('pb.tenantUser = :tenantUser')
                ->setParameter('tenantUser', $filters['tenant_user_id']);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findByBillIdAndStatus(string $billId, PaymentStatus $status): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.bill = :billId')
            ->andWhere('p.status = :status')
            ->setParameter('billId', $billId)
            ->setParameter('status', $status->value)
            ->getQuery()
            ->getResult();
    }
}
