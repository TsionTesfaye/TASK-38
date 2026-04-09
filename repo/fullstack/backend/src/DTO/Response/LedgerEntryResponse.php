<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\LedgerEntry;

readonly class LedgerEntryResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public ?string $booking_id,
        public ?string $bill_id,
        public ?string $payment_id,
        public ?string $refund_id,
        public string $entry_type,
        public string $amount,
        public string $currency,
        public string $occurred_at,
        public ?array $metadata_json,
    ) {}

    public static function fromEntity(LedgerEntry $entry): self
    {
        return new self(
            id: $entry->getId(),
            organization_id: $entry->getOrganizationId(),
            booking_id: $entry->getBooking()?->getId(),
            bill_id: $entry->getBill()?->getId(),
            payment_id: $entry->getPayment()?->getId(),
            refund_id: $entry->getRefund()?->getId(),
            entry_type: $entry->getEntryType()->value,
            amount: $entry->getAmount(),
            currency: $entry->getCurrency(),
            occurred_at: $entry->getOccurredAt()->format(\DateTimeInterface::ATOM),
            metadata_json: $entry->getMetadataJson(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'booking_id' => $this->booking_id,
            'bill_id' => $this->bill_id,
            'payment_id' => $this->payment_id,
            'refund_id' => $this->refund_id,
            'entry_type' => $this->entry_type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'occurred_at' => $this->occurred_at,
            'metadata_json' => $this->metadata_json,
        ];
    }
}
