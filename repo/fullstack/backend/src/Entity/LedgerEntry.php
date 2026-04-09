<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\LedgerEntryType;
use App\Repository\LedgerEntryRepository;

#[ORM\Entity(repositoryClass: LedgerEntryRepository::class)]
#[ORM\Table(name: 'ledger_entries')]
#[ORM\Index(columns: ['organization_id', 'occurred_at'], name: 'IDX_ledger_entries_org_occurred')]
#[ORM\Index(columns: ['bill_id'], name: 'IDX_ledger_entries_bill')]
class LedgerEntry implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Booking::class)]
    #[ORM\JoinColumn(name: 'booking_id', referencedColumnName: 'id', nullable: true)]
    private ?Booking $booking;

    #[ORM\ManyToOne(targetEntity: Bill::class)]
    #[ORM\JoinColumn(name: 'bill_id', referencedColumnName: 'id', nullable: true)]
    private ?Bill $bill;

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'payment_id', referencedColumnName: 'id', nullable: true)]
    private ?Payment $payment;

    #[ORM\ManyToOne(targetEntity: Refund::class)]
    #[ORM\JoinColumn(name: 'refund_id', referencedColumnName: 'id', nullable: true)]
    private ?Refund $refund;

    #[ORM\Column(name: 'entry_type', type: 'string', length: 30, enumType: LedgerEntryType::class)]
    private LedgerEntryType $entryType;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'metadata_json', type: 'json', nullable: true)]
    private ?array $metadataJson;

    public function __construct(string $id, Organization $organization, LedgerEntryType $entryType, string $amount, string $currency, ?Booking $booking = null, ?Bill $bill = null, ?Payment $payment = null, ?Refund $refund = null, ?array $metadataJson = null)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->entryType = $entryType;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->booking = $booking;
        $this->bill = $bill;
        $this->payment = $payment;
        $this->refund = $refund;
        $this->metadataJson = $metadataJson;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getBooking(): ?Booking { return $this->booking; }
    public function getBill(): ?Bill { return $this->bill; }
    public function getPayment(): ?Payment { return $this->payment; }
    public function getRefund(): ?Refund { return $this->refund; }
    public function getEntryType(): LedgerEntryType { return $this->entryType; }
    public function getAmount(): string { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getMetadataJson(): ?array { return $this->metadataJson; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'booking_id' => $this->booking?->getId(),
            'bill_id' => $this->bill?->getId(),
            'payment_id' => $this->payment?->getId(),
            'refund_id' => $this->refund?->getId(),
            'entry_type' => $this->entryType->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'metadata_json' => $this->metadataJson,
        ];
    }
}
