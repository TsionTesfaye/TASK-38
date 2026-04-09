<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class CreateSupplementalBillRequest
{
    public function __construct(
        public string $booking_id,
        public string $amount,
        public string $reason,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            booking_id: $data['booking_id'],
            amount: $data['amount'],
            reason: $data['reason'],
        );
    }
}
