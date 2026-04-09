<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\BookingHold;

readonly class BookingHoldResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $inventory_item_id,
        public string $tenant_user_id,
        public string $request_key,
        public int $held_units,
        public string $start_at,
        public string $end_at,
        public string $expires_at,
        public string $status,
        public ?string $confirmed_booking_id,
        public string $created_at,
    ) {}

    public static function fromEntity(BookingHold $hold): self
    {
        return new self(
            id: $hold->getId(),
            organization_id: $hold->getOrganizationId(),
            inventory_item_id: $hold->getInventoryItemId(),
            tenant_user_id: $hold->getTenantUserId(),
            request_key: $hold->getRequestKey(),
            held_units: $hold->getHeldUnits(),
            start_at: $hold->getStartAt()->format(\DateTimeInterface::ATOM),
            end_at: $hold->getEndAt()->format(\DateTimeInterface::ATOM),
            expires_at: $hold->getExpiresAt()->format(\DateTimeInterface::ATOM),
            status: $hold->getStatus()->value,
            confirmed_booking_id: $hold->getConfirmedBookingId(),
            created_at: $hold->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'inventory_item_id' => $this->inventory_item_id,
            'tenant_user_id' => $this->tenant_user_id,
            'request_key' => $this->request_key,
            'held_units' => $this->held_units,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'expires_at' => $this->expires_at,
            'status' => $this->status,
            'confirmed_booking_id' => $this->confirmed_booking_id,
            'created_at' => $this->created_at,
        ];
    }
}
