<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class ConfirmHoldRequest
{
    public function __construct(
        public string $request_key,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            request_key: $data['request_key'],
        );
    }
}
