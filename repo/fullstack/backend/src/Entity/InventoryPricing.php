<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\RateType;
use App\Repository\InventoryPricingRepository;

#[ORM\Entity(repositoryClass: InventoryPricingRepository::class)]
#[ORM\Table(name: 'inventory_pricing')]
#[ORM\Index(columns: ['inventory_item_id', 'effective_from'], name: 'IDX_inventory_pricing_item_from')]
class InventoryPricing implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: InventoryItem::class)]
    #[ORM\JoinColumn(name: 'inventory_item_id', referencedColumnName: 'id', nullable: false)]
    private InventoryItem $inventoryItem;

    #[ORM\Column(name: 'rate_type', type: 'string', length: 30, enumType: RateType::class)]
    private RateType $rateType;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(name: 'effective_from', type: 'datetime_immutable')]
    private \DateTimeImmutable $effectiveFrom;

    #[ORM\Column(name: 'effective_to', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $effectiveTo;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, Organization $organization, InventoryItem $inventoryItem, RateType $rateType, string $amount, string $currency, \DateTimeImmutable $effectiveFrom, ?\DateTimeImmutable $effectiveTo = null)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->inventoryItem = $inventoryItem;
        $this->rateType = $rateType;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->effectiveFrom = $effectiveFrom;
        $this->effectiveTo = $effectiveTo;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getInventoryItem(): InventoryItem { return $this->inventoryItem; }
    public function getInventoryItemId(): string { return $this->inventoryItem->getId(); }
    public function getRateType(): RateType { return $this->rateType; }
    public function getAmount(): string { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getEffectiveFrom(): \DateTimeImmutable { return $this->effectiveFrom; }
    public function getEffectiveTo(): ?\DateTimeImmutable { return $this->effectiveTo; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'inventory_item_id' => $this->getInventoryItemId(),
            'rate_type' => $this->rateType->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'effective_from' => $this->effectiveFrom->format(\DateTimeInterface::ATOM),
            'effective_to' => $this->effectiveTo?->format(\DateTimeInterface::ATOM),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
