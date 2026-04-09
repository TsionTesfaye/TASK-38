<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\RefundStatus;
use App\Repository\RefundRepository;

#[ORM\Entity(repositoryClass: RefundRepository::class)]
#[ORM\Table(name: 'refunds')]
#[ORM\Index(columns: ['bill_id'], name: 'IDX_refunds_bill')]
class Refund implements \JsonSerializable
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

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'payment_id', referencedColumnName: 'id', nullable: true)]
    private ?Payment $payment;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 500)]
    private string $reason;

    #[ORM\Column(type: 'string', length: 30, enumType: RefundStatus::class)]
    private RefundStatus $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: false)]
    private User $createdByUser;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, Organization $organization, Bill $bill, ?Payment $payment, string $amount, string $reason, RefundStatus $status, User $createdByUser)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->bill = $bill;
        $this->payment = $payment;
        $this->amount = $amount;
        $this->reason = $reason;
        $this->status = $status;
        $this->createdByUser = $createdByUser;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getBill(): Bill { return $this->bill; }
    public function getBillId(): string { return $this->bill->getId(); }
    public function getPayment(): ?Payment { return $this->payment; }
    public function getPaymentId(): ?string { return $this->payment?->getId(); }
    public function getAmount(): string { return $this->amount; }
    public function getReason(): string { return $this->reason; }
    public function getStatus(): RefundStatus { return $this->status; }
    public function getCreatedByUser(): User { return $this->createdByUser; }
    public function getCreatedByUserId(): string { return $this->createdByUser->getId(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'bill_id' => $this->getBillId(),
            'payment_id' => $this->payment?->getId(),
            'amount' => $this->amount,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'created_by_user_id' => $this->getCreatedByUserId(),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
