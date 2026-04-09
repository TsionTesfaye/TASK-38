<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
#[ORM\Index(columns: ['bill_id', 'status'], name: 'IDX_payments_bill_status')]
class Payment implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Bill::class)]
    #[ORM\JoinColumn(name: 'bill_id', referencedColumnName: 'id', nullable: false)]
    private Bill $bill;

    #[ORM\Column(name: 'external_reference', type: 'string', length: 255, nullable: true)]
    private ?string $externalReference;

    #[ORM\Column(name: 'request_id', type: 'string', length: 255, unique: true)]
    private string $requestId;

    #[ORM\Column(type: 'string', length: 30, enumType: PaymentStatus::class)]
    private PaymentStatus $status = PaymentStatus::PENDING;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(name: 'signature_verified', type: 'boolean', options: ['default' => false])]
    private bool $signatureVerified = false;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(name: 'raw_callback_payload_json', type: 'json', nullable: true)]
    private ?array $rawCallbackPayloadJson = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, Organization $organization, Bill $bill, string $requestId, string $currency, string $amount, ?string $externalReference = null)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->bill = $bill;
        $this->requestId = $requestId;
        $this->currency = $currency;
        $this->amount = $amount;
        $this->externalReference = $externalReference;
        $this->receivedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getBill(): Bill { return $this->bill; }
    public function getBillId(): string { return $this->bill->getId(); }
    public function getExternalReference(): ?string { return $this->externalReference; }
    public function getRequestId(): string { return $this->requestId; }
    public function getStatus(): PaymentStatus { return $this->status; }
    public function getCurrency(): string { return $this->currency; }
    public function getAmount(): string { return $this->amount; }
    public function isSignatureVerified(): bool { return $this->signatureVerified; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function getProcessedAt(): ?\DateTimeImmutable { return $this->processedAt; }
    public function getRawCallbackPayloadJson(): ?array { return $this->rawCallbackPayloadJson; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function transitionTo(PaymentStatus $newStatus): void
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \App\Exception\InvalidStateTransitionException($this->status->value, $newStatus->value);
        }
        $this->status = $newStatus;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function setSignatureVerified(bool $verified): void { $this->signatureVerified = $verified; }
    public function setExternalReference(?string $ref): void { $this->externalReference = $ref; }
    public function setRawCallbackPayloadJson(?array $payload): void { $this->rawCallbackPayloadJson = $payload; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'bill_id' => $this->getBillId(),
            'external_reference' => $this->externalReference,
            'request_id' => $this->requestId,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'signature_verified' => $this->signatureVerified,
            'received_at' => $this->receivedAt->format(\DateTimeInterface::ATOM),
            'processed_at' => $this->processedAt?->format(\DateTimeInterface::ATOM),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
