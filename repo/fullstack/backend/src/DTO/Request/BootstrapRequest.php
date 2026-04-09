<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class BootstrapRequest
{
    public function __construct(
        public string $organization_name,
        public string $organization_code,
        public string $admin_username,
        public string $admin_password,
        public string $admin_display_name,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            organization_name: $data['organization_name'],
            organization_code: $data['organization_code'],
            admin_username: $data['admin_username'],
            admin_password: $data['admin_password'],
            admin_display_name: $data['admin_display_name'],
        );
    }
}
