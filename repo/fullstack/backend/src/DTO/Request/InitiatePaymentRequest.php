<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class InitiatePaymentRequest
{
    public function __construct(
        public string $bill_id,
        public string $amount,
        public string $currency,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            bill_id: $data['bill_id'],
            amount: $data['amount'],
            currency: $data['currency'],
        );
    }
}
