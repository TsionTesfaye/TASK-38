<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\NotificationStatus;
use App\Repository\NotificationRepository;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(columns: ['user_id', 'status'], name: 'IDX_notifications_user_status')]
class Notification implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\Column(name: 'event_code', type: 'string', length: 100)]
    private string $eventCode;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'string', length: 30, enumType: NotificationStatus::class)]
    private NotificationStatus $status = NotificationStatus::PENDING;

    #[ORM\Column(name: 'scheduled_for', type: 'datetime_immutable')]
    private \DateTimeImmutable $scheduledFor;

    #[ORM\Column(name: 'delivered_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, Organization $organization, User $user, string $eventCode, string $title, string $body, \DateTimeImmutable $scheduledFor)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->user = $user;
        $this->eventCode = $eventCode;
        $this->title = $title;
        $this->body = $body;
        $this->scheduledFor = $scheduledFor;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getUser(): User { return $this->user; }
    public function getUserId(): string { return $this->user->getId(); }
    public function getEventCode(): string { return $this->eventCode; }
    public function getTitle(): string { return $this->title; }
    public function getBody(): string { return $this->body; }
    public function getStatus(): NotificationStatus { return $this->status; }
    public function getScheduledFor(): \DateTimeImmutable { return $this->scheduledFor; }
    public function getDeliveredAt(): ?\DateTimeImmutable { return $this->deliveredAt; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function transitionTo(NotificationStatus $newStatus): void
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \App\Exception\InvalidStateTransitionException($this->status->value, $newStatus->value);
        }
        $this->status = $newStatus;
    }

    public function markDelivered(): void
    {
        $this->transitionTo(NotificationStatus::DELIVERED);
        $this->deliveredAt = new \DateTimeImmutable();
    }

    public function markRead(): void
    {
        $this->transitionTo(NotificationStatus::READ);
        $this->readAt = new \DateTimeImmutable();
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization->getId(),
            'user_id' => $this->getUserId(),
            'event_code' => $this->eventCode,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status->value,
            'scheduled_for' => $this->scheduledFor->format(\DateTimeInterface::ATOM),
            'delivered_at' => $this->deliveredAt?->format(\DateTimeInterface::ATOM),
            'read_at' => $this->readAt?->format(\DateTimeInterface::ATOM),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
