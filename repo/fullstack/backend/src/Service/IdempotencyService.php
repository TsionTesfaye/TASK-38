<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\IdempotencyKey;
use App\Entity\User;
use App\Repository\IdempotencyKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class IdempotencyService
{
    public function __construct(
        private readonly IdempotencyKeyRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function check(string $userId, string $requestKey): ?array
    {
        $existing = $this->repository->findByUserAndKey($userId, $requestKey);
        if ($existing !== null && !$existing->isExpired()) {
            return $existing->getResponsePayloadJson();
        }
        return null;
    }

    public function store(User $user, string $requestKey, array $responsePayload): void
    {
        $key = new IdempotencyKey(
            Uuid::v4()->toRfc4122(),
            $user,
            $requestKey,
            $responsePayload,
            new \DateTimeImmutable('+24 hours'),
        );
        $this->em->persist($key);
        $this->em->flush();
    }

    public function cleanupExpired(): int
    {
        return $this->repository->deleteExpired();
    }
}
