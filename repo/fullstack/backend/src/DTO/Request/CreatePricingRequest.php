<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class CreatePricingRequest
{
    public function __construct(
        public string $rate_type,
        public string $amount,
        public string $currency,
        public string $effective_from,
        public ?string $effective_to,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            rate_type: $data['rate_type'],
            amount: $data['amount'],
            currency: $data['currency'],
            effective_from: $data['effective_from'],
            effective_to: $data['effective_to'] ?? null,
        );
    }
}
