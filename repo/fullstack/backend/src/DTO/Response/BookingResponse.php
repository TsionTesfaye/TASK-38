<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\Booking;

readonly class BookingResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $inventory_item_id,
        public string $tenant_user_id,
        public ?string $source_hold_id,
        public string $status,
        public string $start_at,
        public string $end_at,
        public int $booked_units,
        public string $currency,
        public string $base_amount,
        public string $final_amount,
        public string $cancellation_fee_amount,
        public string $no_show_penalty_amount,
        public string $created_at,
        public string $updated_at,
        public ?string $canceled_at,
        public ?string $completed_at,
        public ?string $no_show_marked_at,
        public ?string $checked_in_at,
    ) {}

    public static function fromEntity(Booking $booking): self
    {
        return new self(
            id: $booking->getId(),
            organization_id: $booking->getOrganizationId(),
            inventory_item_id: $booking->getInventoryItemId(),
            tenant_user_id: $booking->getTenantUserId(),
            source_hold_id: $booking->getSourceHold()?->getId(),
            status: $booking->getStatus()->value,
            start_at: $booking->getStartAt()->format(\DateTimeInterface::ATOM),
            end_at: $booking->getEndAt()->format(\DateTimeInterface::ATOM),
            booked_units: $booking->getBookedUnits(),
            currency: $booking->getCurrency(),
            base_amount: $booking->getBaseAmount(),
            final_amount: $booking->getFinalAmount(),
            cancellation_fee_amount: $booking->getCancellationFeeAmount(),
            no_show_penalty_amount: $booking->getNoShowPenaltyAmount(),
            created_at: $booking->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updated_at: $booking->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            canceled_at: $booking->getCanceledAt()?->format(\DateTimeInterface::ATOM),
            completed_at: $booking->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            no_show_marked_at: $booking->getNoShowMarkedAt()?->format(\DateTimeInterface::ATOM),
            checked_in_at: $booking->getCheckedInAt()?->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'inventory_item_id' => $this->inventory_item_id,
            'tenant_user_id' => $this->tenant_user_id,
            'source_hold_id' => $this->source_hold_id,
            'status' => $this->status,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'booked_units' => $this->booked_units,
            'currency' => $this->currency,
            'base_amount' => $this->base_amount,
            'final_amount' => $this->final_amount,
            'cancellation_fee_amount' => $this->cancellation_fee_amount,
            'no_show_penalty_amount' => $this->no_show_penalty_amount,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'canceled_at' => $this->canceled_at,
            'completed_at' => $this->completed_at,
            'no_show_marked_at' => $this->no_show_marked_at,
            'checked_in_at' => $this->checked_in_at,
        ];
    }
}
