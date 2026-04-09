<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\IdempotencyKeyRepository;

#[ORM\Entity(repositoryClass: IdempotencyKeyRepository::class)]
#[ORM\Table(name: 'idempotency_keys')]
#[ORM\UniqueConstraint(name: 'UNIQ_idempotency_keys_user_request', columns: ['user_id', 'request_key'])]
#[ORM\Index(columns: ['expires_at'], name: 'IDX_idempotency_keys_expires')]
class IdempotencyKey implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\Column(name: 'request_key', type: 'string', length: 255)]
    private string $requestKey;

    #[ORM\Column(name: 'response_payload_json', type: 'json')]
    private array $responsePayloadJson;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(string $id, User $user, string $requestKey, array $responsePayloadJson, \DateTimeImmutable $expiresAt)
    {
        $this->id = $id;
        $this->user = $user;
        $this->requestKey = $requestKey;
        $this->responsePayloadJson = $responsePayloadJson;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getUserId(): string { return $this->user->getId(); }
    public function getRequestKey(): string { return $this->requestKey; }
    public function getResponsePayloadJson(): array { return $this->responsePayloadJson; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function isExpired(): bool { return $this->expiresAt <= new \DateTimeImmutable(); }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->getUserId(),
            'request_key' => $this->requestKey,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
