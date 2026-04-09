<?php

declare(strict_types=1);

namespace App\DTO\Response;

readonly class AuthTokenResponse
{
    public function __construct(
        public string $access_token,
        public string $refresh_token,
        public int $expires_in,
        public string $session_id,
        public UserResponse $user,
    ) {}

    public function toArray(): array
    {
        return [
            'access_token' => $this->access_token,
            'refresh_token' => $this->refresh_token,
            'expires_in' => $this->expires_in,
            'session_id' => $this->session_id,
            'user' => $this->user->toArray(),
        ];
    }
}
