<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationPreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationPreference>
 */
class NotificationPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationPreference::class);
    }

    public function findByUserAndEvent(string $userId, string $eventCode): ?NotificationPreference
    {
        return $this->findOneBy(['user' => $userId, 'eventCode' => $eventCode]);
    }

    /**
     * @return NotificationPreference[]
     */
    public function findAllByUser(string $userId): array
    {
        return $this->findBy(['user' => $userId]);
    }
}
