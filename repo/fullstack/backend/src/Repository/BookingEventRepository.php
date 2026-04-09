<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookingEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookingEvent>
 */
class BookingEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingEvent::class);
    }

    /**
     * @return BookingEvent[]
     */
    public function findByBookingId(string $bookingId): array
    {
        return $this->createQueryBuilder('be')
            ->where('be.booking = :bookingId')
            ->setParameter('bookingId', $bookingId)
            ->orderBy('be.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
