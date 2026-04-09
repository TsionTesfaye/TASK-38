<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class LoginRequest
{
    public function __construct(
        public string $username,
        public string $password,
        public string $device_label,
        public string $client_device_id,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            username: $data['username'],
            password: $data['password'],
            device_label: $data['device_label'],
            client_device_id: $data['client_device_id'],
        );
    }
}
