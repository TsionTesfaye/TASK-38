<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\NotificationPreferenceRepository;

#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notification_preferences')]
#[ORM\UniqueConstraint(name: 'UNIQ_notification_prefs_user_event', columns: ['user_id', 'event_code'])]
class NotificationPreference implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\Column(name: 'event_code', type: 'string', length: 100)]
    private string $eventCode;

    #[ORM\Column(name: 'is_enabled', type: 'boolean', options: ['default' => true])]
    private bool $isEnabled = true;

    #[ORM\Column(name: 'dnd_start_local', type: 'string', length: 5, options: ['default' => '21:00'])]
    private string $dndStartLocal = '21:00';

    #[ORM\Column(name: 'dnd_end_local', type: 'string', length: 5, options: ['default' => '08:00'])]
    private string $dndEndLocal = '08:00';

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, User $user, string $eventCode)
    {
        $this->id = $id;
        $this->user = $user;
        $this->eventCode = $eventCode;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getEventCode(): string { return $this->eventCode; }
    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $enabled): void { $this->isEnabled = $enabled; $this->updatedAt = new \DateTimeImmutable(); }
    public function getDndStartLocal(): string { return $this->dndStartLocal; }
    public function setDndStartLocal(string $start): void { $this->dndStartLocal = $start; $this->updatedAt = new \DateTimeImmutable(); }
    public function getDndEndLocal(): string { return $this->dndEndLocal; }
    public function setDndEndLocal(string $end): void { $this->dndEndLocal = $end; $this->updatedAt = new \DateTimeImmutable(); }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user->getId(),
            'event_code' => $this->eventCode,
            'is_enabled' => $this->isEnabled,
            'dnd_start_local' => $this->dndStartLocal,
            'dnd_end_local' => $this->dndEndLocal,
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
