<?php

declare(strict_types=1);

namespace App\DTO\Response;

readonly class HealthResponse
{
    public function __construct(
        public string $status,
        public array $checks,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'checks' => $this->checks,
        ];
    }
}
