<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class RegisterTerminalRequest
{
    public function __construct(
        public string $terminal_code,
        public string $display_name,
        public string $location_group,
        public string $language_code = 'en',
        public bool $accessibility_mode = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            terminal_code: $data['terminal_code'],
            display_name: $data['display_name'],
            location_group: $data['location_group'],
            language_code: $data['language_code'] ?? 'en',
            accessibility_mode: (bool) ($data['accessibility_mode'] ?? false),
        );
    }
}
