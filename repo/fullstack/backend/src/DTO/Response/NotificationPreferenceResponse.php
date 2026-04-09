<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\NotificationPreference;

readonly class NotificationPreferenceResponse
{
    public function __construct(
        public string $id,
        public string $user_id,
        public string $event_code,
        public bool $is_enabled,
        public string $dnd_start_local,
        public string $dnd_end_local,
        public string $updated_at,
    ) {}

    public static function fromEntity(NotificationPreference $pref): self
    {
        return new self(
            id: $pref->getId(),
            user_id: $pref->getUser()->getId(),
            event_code: $pref->getEventCode(),
            is_enabled: $pref->isEnabled(),
            dnd_start_local: $pref->getDndStartLocal(),
            dnd_end_local: $pref->getDndEndLocal(),
            updated_at: $pref->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'event_code' => $this->event_code,
            'is_enabled' => $this->is_enabled,
            'dnd_start_local' => $this->dnd_start_local,
            'dnd_end_local' => $this->dnd_end_local,
            'updated_at' => $this->updated_at,
        ];
    }
}
