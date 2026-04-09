<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class PaymentCallbackRequest
{
    public function __construct(
        public string $request_id,
        public string $signature,
        public string $status,
        public string $amount,
        public string $currency,
        public ?string $external_reference,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            request_id: $data['request_id'],
            signature: $data['signature'],
            status: $data['status'],
            amount: $data['amount'],
            currency: $data['currency'],
            external_reference: $data['external_reference'] ?? null,
        );
    }
}
