<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\Terminal;

readonly class TerminalResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $terminal_code,
        public string $display_name,
        public string $location_group,
        public string $language_code,
        public bool $accessibility_mode,
        public bool $is_active,
        public ?string $last_sync_at,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromEntity(Terminal $terminal): self
    {
        return new self(
            id: $terminal->getId(),
            organization_id: $terminal->getOrganizationId(),
            terminal_code: $terminal->getTerminalCode(),
            display_name: $terminal->getDisplayName(),
            location_group: $terminal->getLocationGroup(),
            language_code: $terminal->getLanguageCode(),
            accessibility_mode: $terminal->getAccessibilityMode(),
            is_active: $terminal->isActive(),
            last_sync_at: $terminal->getLastSyncAt()?->format(\DateTimeInterface::ATOM),
            created_at: $terminal->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updated_at: $terminal->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'terminal_code' => $this->terminal_code,
            'display_name' => $this->display_name,
            'location_group' => $this->location_group,
            'language_code' => $this->language_code,
            'accessibility_mode' => $this->accessibility_mode,
            'is_active' => $this->is_active,
            'last_sync_at' => $this->last_sync_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
