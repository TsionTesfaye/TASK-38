<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class InitiateTransferRequest
{
    public function __construct(
        public string $terminal_id,
        public string $package_name,
        public string $checksum,
        public int $total_chunks,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            terminal_id: $data['terminal_id'],
            package_name: $data['package_name'],
            checksum: $data['checksum'],
            total_chunks: (int) $data['total_chunks'],
        );
    }
}
