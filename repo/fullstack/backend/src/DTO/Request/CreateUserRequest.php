<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class CreateUserRequest
{
    public function __construct(
        public string $username,
        public string $password,
        public string $display_name,
        public string $role,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            username: $data['username'],
            password: $data['password'],
            display_name: $data['display_name'],
            role: $data['role'],
        );
    }
}
