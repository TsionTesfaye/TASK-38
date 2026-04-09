<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\User;

readonly class UserResponse
{
    public function __construct(
        public string $id,
        public string $username,
        public string $display_name,
        public string $role,
        public bool $is_active,
        public bool $is_frozen,
        public string $organization_id,
        public string $created_at,
    ) {}

    public static function fromEntity(User $user): self
    {
        return new self(
            id: $user->getId(),
            username: $user->getUsername(),
            display_name: $user->getDisplayName(),
            role: $user->getRole()->value,
            is_active: $user->isActive(),
            is_frozen: $user->isFrozen(),
            organization_id: $user->getOrganizationId(),
            created_at: $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'is_frozen' => $this->is_frozen,
            'organization_id' => $this->organization_id,
            'created_at' => $this->created_at,
        ];
    }
}
