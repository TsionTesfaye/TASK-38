<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\Notification;

readonly class NotificationResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $user_id,
        public string $event_code,
        public string $title,
        public string $body,
        public string $status,
        public string $scheduled_for,
        public ?string $delivered_at,
        public ?string $read_at,
        public string $created_at,
    ) {}

    public static function fromEntity(Notification $notification): self
    {
        return new self(
            id: $notification->getId(),
            organization_id: $notification->getOrganization()->getId(),
            user_id: $notification->getUserId(),
            event_code: $notification->getEventCode(),
            title: $notification->getTitle(),
            body: $notification->getBody(),
            status: $notification->getStatus()->value,
            scheduled_for: $notification->getScheduledFor()->format(\DateTimeInterface::ATOM),
            delivered_at: $notification->getDeliveredAt()?->format(\DateTimeInterface::ATOM),
            read_at: $notification->getReadAt()?->format(\DateTimeInterface::ATOM),
            created_at: $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'user_id' => $this->user_id,
            'event_code' => $this->event_code,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'scheduled_for' => $this->scheduled_for,
            'delivered_at' => $this->delivered_at,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
