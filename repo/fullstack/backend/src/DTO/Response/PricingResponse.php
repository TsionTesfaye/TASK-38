<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\InventoryPricing;

readonly class PricingResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $inventory_item_id,
        public string $rate_type,
        public string $amount,
        public string $currency,
        public string $effective_from,
        public ?string $effective_to,
        public string $created_at,
    ) {}

    public static function fromEntity(InventoryPricing $pricing): self
    {
        return new self(
            id: $pricing->getId(),
            organization_id: $pricing->getOrganizationId(),
            inventory_item_id: $pricing->getInventoryItemId(),
            rate_type: $pricing->getRateType()->value,
            amount: $pricing->getAmount(),
            currency: $pricing->getCurrency(),
            effective_from: $pricing->getEffectiveFrom()->format(\DateTimeInterface::ATOM),
            effective_to: $pricing->getEffectiveTo()?->format(\DateTimeInterface::ATOM),
            created_at: $pricing->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'inventory_item_id' => $this->inventory_item_id,
            'rate_type' => $this->rate_type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from,
            'effective_to' => $this->effective_to,
            'created_at' => $this->created_at,
        ];
    }
}
