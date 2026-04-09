<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class IssueRefundRequest
{
    public function __construct(
        public string $bill_id,
        public string $amount,
        public string $reason,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            bill_id: $data['bill_id'],
            amount: $data['amount'],
            reason: $data['reason'],
        );
    }
}
