<?php

declare(strict_types=1);

namespace App\DTO\Response;

readonly class PaginatedResponse
{
    public function __construct(
        public array $data,
        public int $page,
        public int $per_page,
        public int $total,
        public bool $has_next,
    ) {}

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'page' => $this->page,
            'per_page' => $this->per_page,
            'total' => $this->total,
            'has_next' => $this->has_next,
        ];
    }
}
