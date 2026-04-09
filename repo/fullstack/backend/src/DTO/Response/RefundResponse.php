<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\Refund;

readonly class RefundResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $bill_id,
        public ?string $payment_id,
        public string $amount,
        public string $reason,
        public string $status,
        public string $created_by_user_id,
        public string $created_at,
    ) {}

    public static function fromEntity(Refund $refund): self
    {
        return new self(
            id: $refund->getId(),
            organization_id: $refund->getOrganizationId(),
            bill_id: $refund->getBillId(),
            payment_id: $refund->getPaymentId(),
            amount: $refund->getAmount(),
            reason: $refund->getReason(),
            status: $refund->getStatus()->value,
            created_by_user_id: $refund->getCreatedByUserId(),
            created_at: $refund->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'bill_id' => $this->bill_id,
            'payment_id' => $this->payment_id,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'status' => $this->status,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at,
        ];
    }
}
