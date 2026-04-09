<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\Payment;

readonly class PaymentResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $bill_id,
        public string $request_id,
        public ?string $external_reference,
        public string $status,
        public string $currency,
        public string $amount,
        public bool $signature_verified,
        public string $received_at,
        public ?string $processed_at,
        public string $created_at,
    ) {}

    public static function fromEntity(Payment $payment): self
    {
        return new self(
            id: $payment->getId(),
            organization_id: $payment->getOrganizationId(),
            bill_id: $payment->getBillId(),
            request_id: $payment->getRequestId(),
            external_reference: $payment->getExternalReference(),
            status: $payment->getStatus()->value,
            currency: $payment->getCurrency(),
            amount: $payment->getAmount(),
            signature_verified: $payment->isSignatureVerified(),
            received_at: $payment->getReceivedAt()->format(\DateTimeInterface::ATOM),
            processed_at: $payment->getProcessedAt()?->format(\DateTimeInterface::ATOM),
            created_at: $payment->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'bill_id' => $this->bill_id,
            'request_id' => $this->request_id,
            'external_reference' => $this->external_reference,
            'status' => $this->status,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'signature_verified' => $this->signature_verified,
            'received_at' => $this->received_at,
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
        ];
    }
}
