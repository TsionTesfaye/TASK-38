<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Refund;
use App\Enum\RefundStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Refund>
 */
class RefundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Refund::class);
    }

    /**
     * @return Refund[]
     */
    public function findByBillId(string $billId): array
    {
        return $this->findBy(['bill' => $billId]);
    }

    public function sumIssuedForBill(string $billId): string
    {
        $result = $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.amount), 0)')
            ->where('r.bill = :billId')
            ->andWhere('r.status = :status')
            ->setParameter('billId', $billId)
            ->setParameter('status', 'issued')
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $result;
    }

    public function findByIdAndOrg(string $id, string $orgId): ?Refund
    {
        return $this->findOneBy(['id' => $id, 'organization' => $orgId]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return Refund[]
     */
    public function findByOrg(string $orgId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['status'])) {
            $qb->andWhere('r.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['bill_id'])) {
            $qb->andWhere('r.bill = :billId')
                ->setParameter('billId', $filters['bill_id']);
        }

        // Tenant isolation: Refund has no direct tenantUser field — scope via bill.
        // This filter is ALWAYS set by the service layer for TENANT-role users.
        if (isset($filters['tenant_user_id'])) {
            $qb->join('r.bill', 'rb')
                ->andWhere('rb.tenantUser = :tenantUser')
                ->setParameter('tenantUser', $filters['tenant_user_id']);
        }

        $offset = ($page - 1) * $perPage;

        return $qb->orderBy('r.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrg(string $orgId, array $filters): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['status'])) {
            $qb->andWhere('r.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['bill_id'])) {
            $qb->andWhere('r.bill = :billId')
                ->setParameter('billId', $filters['bill_id']);
        }

        // Tenant isolation: scope via bill when tenant_user_id is present.
        if (isset($filters['tenant_user_id'])) {
            $qb->join('r.bill', 'rb')
                ->andWhere('rb.tenantUser = :tenantUser')
                ->setParameter('tenantUser', $filters['tenant_user_id']);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findByBillIdAndStatus(string $billId, RefundStatus $status): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.bill = :billId')
            ->andWhere('r.status = :status')
            ->setParameter('billId', $billId)
            ->setParameter('status', $status->value)
            ->getQuery()
            ->getResult();
    }
}
