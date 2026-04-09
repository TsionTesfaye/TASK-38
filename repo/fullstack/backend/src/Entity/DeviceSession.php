<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\DeviceSessionRepository;

#[ORM\Entity(repositoryClass: DeviceSessionRepository::class)]
#[ORM\Table(name: 'device_sessions')]
#[ORM\Index(columns: ['user_id'], name: 'IDX_device_sessions_user')]
#[ORM\Index(columns: ['user_id', 'revoked_at'], name: 'IDX_device_sessions_user_revoked')]
class DeviceSession implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\Column(name: 'refresh_token_hash', type: 'string', length: 255)]
    private string $refreshTokenHash;

    #[ORM\Column(name: 'device_label', type: 'string', length: 255)]
    private string $deviceLabel;

    #[ORM\Column(name: 'client_device_id', type: 'string', length: 255)]
    private string $clientDeviceId;

    #[ORM\Column(name: 'issued_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(string $id, User $user, string $refreshTokenHash, string $deviceLabel, string $clientDeviceId, \DateTimeImmutable $expiresAt)
    {
        $this->id = $id;
        $this->user = $user;
        $this->refreshTokenHash = $refreshTokenHash;
        $this->deviceLabel = $deviceLabel;
        $this->clientDeviceId = $clientDeviceId;
        $this->issuedAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getUserId(): string { return $this->user->getId(); }
    public function getRefreshTokenHash(): string { return $this->refreshTokenHash; }
    public function getDeviceLabel(): string { return $this->deviceLabel; }
    public function getClientDeviceId(): string { return $this->clientDeviceId; }
    public function getIssuedAt(): \DateTimeImmutable { return $this->issuedAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }

    public function updateLastSeen(): void { $this->lastSeenAt = new \DateTimeImmutable(); }

    public function revoke(): void
    {
        if ($this->isRevoked()) {
            return; // already revoked, idempotent
        }
        if ($this->revokedAt !== null) {
            return;
        }
        $this->revokedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null && $this->expiresAt > new \DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->getUserId(),
            'device_label' => $this->deviceLabel,
            'client_device_id' => $this->clientDeviceId,
            'issued_at' => $this->issuedAt->format(\DateTimeInterface::ATOM),
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::ATOM),
            'last_seen_at' => $this->lastSeenAt->format(\DateTimeInterface::ATOM),
            'revoked_at' => $this->revokedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
