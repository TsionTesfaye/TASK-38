<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\Bill;

readonly class BillResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public ?string $booking_id,
        public string $tenant_user_id,
        public string $bill_type,
        public string $status,
        public string $currency,
        public string $original_amount,
        public string $outstanding_amount,
        public ?string $due_at,
        public string $issued_at,
        public ?string $paid_at,
        public ?string $voided_at,
        public ?string $pdf_path,
    ) {}

    public static function fromEntity(Bill $bill): self
    {
        return new self(
            id: $bill->getId(),
            organization_id: $bill->getOrganizationId(),
            booking_id: $bill->getBookingId(),
            tenant_user_id: $bill->getTenantUserId(),
            bill_type: $bill->getBillType()->value,
            status: $bill->getStatus()->value,
            currency: $bill->getCurrency(),
            original_amount: $bill->getOriginalAmount(),
            outstanding_amount: $bill->getOutstandingAmount(),
            due_at: $bill->getDueAt()?->format(\DateTimeInterface::ATOM),
            issued_at: $bill->getIssuedAt()->format(\DateTimeInterface::ATOM),
            paid_at: $bill->getPaidAt()?->format(\DateTimeInterface::ATOM),
            voided_at: $bill->getVoidedAt()?->format(\DateTimeInterface::ATOM),
            pdf_path: $bill->getPdfPath(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'booking_id' => $this->booking_id,
            'tenant_user_id' => $this->tenant_user_id,
            'bill_type' => $this->bill_type,
            'status' => $this->status,
            'currency' => $this->currency,
            'original_amount' => $this->original_amount,
            'outstanding_amount' => $this->outstanding_amount,
            'due_at' => $this->due_at,
            'issued_at' => $this->issued_at,
            'paid_at' => $this->paid_at,
            'voided_at' => $this->voided_at,
            'pdf_path' => $this->pdf_path,
        ];
    }
}
