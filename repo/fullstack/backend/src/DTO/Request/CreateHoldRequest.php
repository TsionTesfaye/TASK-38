<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class CreateHoldRequest
{
    public function __construct(
        public string $inventory_item_id,
        public int $units,
        public string $start_at,
        public string $end_at,
        public string $request_key,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            inventory_item_id: $data['inventory_item_id'],
            units: (int) $data['units'],
            start_at: $data['start_at'],
            end_at: $data['end_at'],
            request_key: $data['request_key'],
        );
    }
}
