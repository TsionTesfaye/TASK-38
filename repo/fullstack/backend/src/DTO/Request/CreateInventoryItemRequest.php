<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class CreateInventoryItemRequest
{
    public function __construct(
        public string $asset_code,
        public string $name,
        public string $asset_type,
        public string $location_name,
        public string $capacity_mode,
        public int $total_capacity,
        public string $timezone,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            asset_code: $data['asset_code'],
            name: $data['name'],
            asset_type: $data['asset_type'],
            location_name: $data['location_name'],
            capacity_mode: $data['capacity_mode'],
            total_capacity: (int) $data['total_capacity'],
            timezone: $data['timezone'],
        );
    }
}
