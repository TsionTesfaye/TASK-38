<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class UpdateNotificationPreferenceRequest
{
    public function __construct(
        public bool $is_enabled,
        public ?string $dnd_start_local,
        public ?string $dnd_end_local,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            is_enabled: (bool) $data['is_enabled'],
            dnd_start_local: $data['dnd_start_local'] ?? null,
            dnd_end_local: $data['dnd_end_local'] ?? null,
        );
    }
}
