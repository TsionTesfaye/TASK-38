<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\TerminalPlaylist;

readonly class PlaylistResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $name,
        public string $location_group,
        public string $schedule_rule,
        public bool $is_active,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromEntity(TerminalPlaylist $playlist): self
    {
        return new self(
            id: $playlist->getId(),
            organization_id: $playlist->getOrganizationId(),
            name: $playlist->getName(),
            location_group: $playlist->getLocationGroup(),
            schedule_rule: $playlist->getScheduleRule(),
            is_active: $playlist->isActive(),
            created_at: $playlist->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updated_at: $playlist->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'location_group' => $this->location_group,
            'schedule_rule' => $this->schedule_rule,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
