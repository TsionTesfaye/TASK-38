<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class ChangePasswordRequest
{
    public function __construct(
        public string $current_password,
        public string $new_password,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            current_password: $data['current_password'],
            new_password: $data['new_password'],
        );
    }
}
