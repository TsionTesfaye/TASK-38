<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Bill;
use App\Enum\BillType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bill>
 */
class BillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bill::class);
    }

    public function findByIdAndOrg(string $id, string $orgId): ?Bill
    {
        return $this->findOneBy(['id' => $id, 'organization' => $orgId]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return Bill[]
     */
    public function findByOrg(string $orgId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['status'])) {
            $qb->andWhere('b.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['bill_type'])) {
            $qb->andWhere('b.billType = :billType')
                ->setParameter('billType', $filters['bill_type']);
        }

        if (isset($filters['booking_id'])) {
            $qb->andWhere('b.booking = :bookingId')
                ->setParameter('bookingId', $filters['booking_id']);
        }

        if (isset($filters['tenant_user_id'])) {
            $qb->andWhere('b.tenantUser = :tenantUser')
                ->setParameter('tenantUser', $filters['tenant_user_id']);
        }

        $offset = ($page - 1) * $perPage;

        return $qb->orderBy('b.issuedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Bill[]
     */
    public function findByTenant(string $userId, array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.tenantUser = :userId')
            ->setParameter('userId', $userId);

        if (isset($filters['status'])) {
            $qb->andWhere('b.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['booking_id'])) {
            $qb->andWhere('b.booking = :bookingId')
                ->setParameter('bookingId', $filters['booking_id']);
        }

        $offset = ($page - 1) * $perPage;

        return $qb->orderBy('b.issuedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Bill[]
     */
    public function countByOrg(string $orgId, array $filters): int
    {
        $qb = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.organization = :orgId')
            ->setParameter('orgId', $orgId);

        if (isset($filters['status'])) {
            $qb->andWhere('b.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['bill_type'])) {
            $qb->andWhere('b.billType = :billType')
                ->setParameter('billType', $filters['bill_type']);
        }

        if (isset($filters['booking_id'])) {
            $qb->andWhere('b.booking = :bookingId')
                ->setParameter('bookingId', $filters['booking_id']);
        }

        if (isset($filters['tenant_user_id'])) {
            $qb->andWhere('b.tenantUser = :tenantUser')
                ->setParameter('tenantUser', $filters['tenant_user_id']);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findByBookingId(string $bookingId): array
    {
        return $this->findBy(['booking' => $bookingId]);
    }

    public function sumSuccessfulPayments(string $billId): string
    {
        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('COALESCE(SUM(p.amount), 0) AS total')
            ->from('App\Entity\Payment', 'p')
            ->where('p.bill = :billId')
            ->andWhere('p.status = :status')
            ->setParameter('billId', $billId)
            ->setParameter('status', 'succeeded')
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $result;
    }

    public function sumIssuedRefunds(string $billId): string
    {
        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('COALESCE(SUM(r.amount), 0) AS total')
            ->from('App\Entity\Refund', 'r')
            ->where('r.bill = :billId')
            ->andWhere('r.status = :status')
            ->setParameter('billId', $billId)
            ->setParameter('status', 'issued')
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $result;
    }

    public function findByBookingAndPeriod(string $bookingId, string $period, BillType $type): ?Bill
    {
        return $this->createQueryBuilder('b')
            ->where('b.booking = :bookingId')
            ->andWhere('b.billType = :type')
            ->andWhere('b.issuedAt LIKE :period')
            ->setParameter('bookingId', $bookingId)
            ->setParameter('type', $type->value)
            ->setParameter('period', $period . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByOrgForReconciliation(string $orgId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.organization = :orgId')
            ->andWhere('b.status != :voided')
            ->setParameter('orgId', $orgId)
            ->setParameter('voided', 'voided')
            ->getQuery()
            ->getResult();
    }
}
