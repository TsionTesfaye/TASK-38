<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class RescheduleBookingRequest
{
    public function __construct(
        public string $new_hold_id,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            new_hold_id: $data['new_hold_id'],
        );
    }
}
