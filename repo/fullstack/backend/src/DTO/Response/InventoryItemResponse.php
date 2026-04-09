<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\InventoryItem;

readonly class InventoryItemResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $asset_code,
        public string $name,
        public string $asset_type,
        public string $location_name,
        public string $capacity_mode,
        public int $total_capacity,
        public string $timezone,
        public bool $is_active,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromEntity(InventoryItem $item): self
    {
        return new self(
            id: $item->getId(),
            organization_id: $item->getOrganizationId(),
            asset_code: $item->getAssetCode(),
            name: $item->getName(),
            asset_type: $item->getAssetType(),
            location_name: $item->getLocationName(),
            capacity_mode: $item->getCapacityMode()->value,
            total_capacity: $item->getTotalCapacity(),
            timezone: $item->getTimezone(),
            is_active: $item->isActive(),
            created_at: $item->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updated_at: $item->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'asset_code' => $this->asset_code,
            'name' => $this->name,
            'asset_type' => $this->asset_type,
            'location_name' => $this->location_name,
            'capacity_mode' => $this->capacity_mode,
            'total_capacity' => $this->total_capacity,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
