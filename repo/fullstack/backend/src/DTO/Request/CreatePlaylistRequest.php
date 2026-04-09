<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class CreatePlaylistRequest
{
    public function __construct(
        public string $name,
        public string $location_group,
        public string $schedule_rule,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            location_group: $data['location_group'],
            schedule_rule: $data['schedule_rule'],
        );
    }
}
