<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\AuditLogRepository;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(columns: ['organization_id', 'created_at'], name: 'IDX_audit_logs_org_created')]
#[ORM\Index(columns: ['object_type', 'object_id'], name: 'IDX_audit_logs_object')]
class AuditLog implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_user_id', referencedColumnName: 'id', nullable: true)]
    private ?User $actorUser;

    #[ORM\Column(name: 'actor_username_snapshot', type: 'string', length: 180)]
    private string $actorUsernameSnapshot;

    #[ORM\Column(name: 'action_code', type: 'string', length: 100)]
    private string $actionCode;

    #[ORM\Column(name: 'object_type', type: 'string', length: 100)]
    private string $objectType;

    #[ORM\Column(name: 'object_id', type: 'string', length: 36)]
    private string $objectId;

    #[ORM\Column(name: 'before_json', type: 'json', nullable: true)]
    private ?array $beforeJson;

    #[ORM\Column(name: 'after_json', type: 'json', nullable: true)]
    private ?array $afterJson;

    #[ORM\Column(name: 'client_device_id', type: 'string', length: 255, nullable: true)]
    private ?string $clientDeviceId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, Organization $organization, ?User $actorUser, string $actorUsernameSnapshot, string $actionCode, string $objectType, string $objectId, ?array $beforeJson = null, ?array $afterJson = null, ?string $clientDeviceId = null)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->actorUser = $actorUser;
        $this->actorUsernameSnapshot = $actorUsernameSnapshot;
        $this->actionCode = $actionCode;
        $this->objectType = $objectType;
        $this->objectId = $objectId;
        $this->beforeJson = $beforeJson;
        $this->afterJson = $afterJson;
        $this->clientDeviceId = $clientDeviceId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getActorUser(): ?User { return $this->actorUser; }
    public function getActorUsernameSnapshot(): string { return $this->actorUsernameSnapshot; }
    public function getActionCode(): string { return $this->actionCode; }
    public function getObjectType(): string { return $this->objectType; }
    public function getObjectId(): string { return $this->objectId; }
    public function getBeforeJson(): ?array { return $this->beforeJson; }
    public function getAfterJson(): ?array { return $this->afterJson; }
    public function getClientDeviceId(): ?string { return $this->clientDeviceId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function jsonSerialize(): array
    {
        $objectId = $this->objectId;
        if (strlen($objectId) > 4) {
            $objectId = str_repeat('*', strlen($objectId) - 4) . substr($objectId, -4);
        }

        return [
            'id' => $this->id,
            'organization_id' => $this->organization->getId(),
            'actor_user_id' => $this->actorUser?->getId(),
            'actor_username_snapshot' => $this->actorUsernameSnapshot,
            'action_code' => $this->actionCode,
            'object_type' => $this->objectType,
            'object_id' => $objectId,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
