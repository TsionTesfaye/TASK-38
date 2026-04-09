<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\Organization;

readonly class OrganizationResponse
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public bool $is_active,
        public string $default_currency,
        public string $created_at,
    ) {}

    public static function fromEntity(Organization $organization): self
    {
        return new self(
            id: $organization->getId(),
            code: $organization->getCode(),
            name: $organization->getName(),
            is_active: $organization->isActive(),
            default_currency: $organization->getDefaultCurrency(),
            created_at: $organization->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'default_currency' => $this->default_currency,
            'created_at' => $this->created_at,
        ];
    }
}
