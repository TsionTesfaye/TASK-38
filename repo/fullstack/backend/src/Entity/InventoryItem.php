<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\CapacityMode;
use App\Repository\InventoryItemRepository;

#[ORM\Entity(repositoryClass: InventoryItemRepository::class)]
#[ORM\Table(name: 'inventory_items')]
#[ORM\UniqueConstraint(name: 'UNIQ_inventory_items_org_asset', columns: ['organization_id', 'asset_code'])]
#[ORM\Index(columns: ['organization_id'], name: 'IDX_inventory_items_organization')]
class InventoryItem implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\Column(name: 'asset_code', type: 'string', length: 100)]
    private string $assetCode;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(name: 'asset_type', type: 'string', length: 100)]
    private string $assetType;

    #[ORM\Column(name: 'location_name', type: 'string', length: 255)]
    private string $locationName;

    #[ORM\Column(name: 'capacity_mode', type: 'string', length: 30, enumType: CapacityMode::class)]
    private CapacityMode $capacityMode;

    #[ORM\Column(name: 'total_capacity', type: 'integer')]
    private int $totalCapacity;

    #[ORM\Column(type: 'string', length: 100, options: ['default' => 'UTC'])]
    private string $timezone = 'UTC';

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Organization $organization, string $assetCode, string $name, string $assetType, string $locationName, CapacityMode $capacityMode, int $totalCapacity, string $timezone = 'UTC')
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->assetCode = $assetCode;
        $this->name = $name;
        $this->assetType = $assetType;
        $this->locationName = $locationName;
        $this->capacityMode = $capacityMode;
        $this->totalCapacity = $totalCapacity;
        $this->timezone = $timezone;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getAssetCode(): string { return $this->assetCode; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; $this->updatedAt = new \DateTimeImmutable(); }
    public function getAssetType(): string { return $this->assetType; }
    public function getLocationName(): string { return $this->locationName; }
    public function setLocationName(string $loc): void { $this->locationName = $loc; $this->updatedAt = new \DateTimeImmutable(); }
    public function getCapacityMode(): CapacityMode { return $this->capacityMode; }
    public function getTotalCapacity(): int { return $this->totalCapacity; }
    public function setTotalCapacity(int $cap): void { $this->totalCapacity = $cap; $this->updatedAt = new \DateTimeImmutable(); }
    public function getTimezone(): string { return $this->timezone; }
    public function setTimezone(string $tz): void { $this->timezone = $tz; $this->updatedAt = new \DateTimeImmutable(); }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): void { $this->isActive = $active; $this->updatedAt = new \DateTimeImmutable(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'asset_code' => $this->assetCode,
            'name' => $this->name,
            'asset_type' => $this->assetType,
            'location_name' => $this->locationName,
            'capacity_mode' => $this->capacityMode->value,
            'total_capacity' => $this->totalCapacity,
            'timezone' => $this->timezone,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
