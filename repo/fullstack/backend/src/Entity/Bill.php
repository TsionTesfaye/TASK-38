<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\BillType;
use App\Enum\BillStatus;
use App\Repository\BillRepository;

#[ORM\Entity(repositoryClass: BillRepository::class)]
#[ORM\Table(name: 'bills')]
#[ORM\Index(columns: ['organization_id', 'tenant_user_id'], name: 'IDX_bills_org_tenant')]
#[ORM\Index(columns: ['organization_id', 'status'], name: 'IDX_bills_org_status')]
class Bill implements \JsonSerializable
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

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'tenant_user_id', referencedColumnName: 'id', nullable: false)]
    private User $tenantUser;

    #[ORM\Column(name: 'bill_type', type: 'string', length: 30, enumType: BillType::class)]
    private BillType $billType;

    #[ORM\Column(type: 'string', length: 30, enumType: BillStatus::class)]
    private BillStatus $status = BillStatus::OPEN;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(name: 'original_amount', type: 'decimal', precision: 12, scale: 2)]
    private string $originalAmount;

    #[ORM\Column(name: 'outstanding_amount', type: 'decimal', precision: 12, scale: 2)]
    private string $outstandingAmount;

    #[ORM\Column(name: 'due_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueAt;

    #[ORM\Column(name: 'issued_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'paid_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(name: 'voided_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    #[ORM\Column(name: 'pdf_path', type: 'string', length: 500, nullable: true)]
    private ?string $pdfPath = null;

    public function __construct(string $id, Organization $organization, ?Booking $booking, User $tenantUser, BillType $billType, string $currency, string $originalAmount, ?\DateTimeImmutable $dueAt = null)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->booking = $booking;
        $this->tenantUser = $tenantUser;
        $this->billType = $billType;
        $this->currency = $currency;
        $this->originalAmount = $originalAmount;
        $this->outstandingAmount = $originalAmount;
        $this->dueAt = $dueAt;
        $this->issuedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getBooking(): ?Booking { return $this->booking; }
    public function getBookingId(): ?string { return $this->booking?->getId(); }
    public function getTenantUser(): User { return $this->tenantUser; }
    public function getTenantUserId(): string { return $this->tenantUser->getId(); }
    public function getBillType(): BillType { return $this->billType; }
    public function getStatus(): BillStatus { return $this->status; }
    public function getCurrency(): string { return $this->currency; }
    public function getOriginalAmount(): string { return $this->originalAmount; }
    public function getOutstandingAmount(): string { return $this->outstandingAmount; }
    public function getDueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function getIssuedAt(): \DateTimeImmutable { return $this->issuedAt; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function getVoidedAt(): ?\DateTimeImmutable { return $this->voidedAt; }
    public function getPdfPath(): ?string { return $this->pdfPath; }
    public function setPdfPath(?string $path): void { $this->pdfPath = $path; }

    public function transitionTo(BillStatus $newStatus): void
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \App\Exception\InvalidStateTransitionException($this->status->value, $newStatus->value);
        }
        $this->status = $newStatus;
        if ($newStatus === BillStatus::PAID) {
            $this->paidAt = new \DateTimeImmutable();
        }
        if ($newStatus === BillStatus::VOIDED) {
            $this->voidedAt = new \DateTimeImmutable();
        }
    }

    /**
     * Update outstanding amount. Must only be called by BillingService::updateBillStatus()
     * or BillingService::voidBill() within a transaction. Validates non-negative.
     */
    public function setOutstandingAmount(string $amount): void
    {
        if (bccomp($amount, '0.00', 2) < 0) {
            throw new \DomainException('Outstanding amount cannot be negative');
        }
        $this->outstandingAmount = $amount;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'booking_id' => $this->getBookingId(),
            'tenant_user_id' => $this->getTenantUserId(),
            'bill_type' => $this->billType->value,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'original_amount' => $this->originalAmount,
            'outstanding_amount' => $this->outstandingAmount,
            'due_at' => $this->dueAt?->format(\DateTimeInterface::ATOM),
            'issued_at' => $this->issuedAt->format(\DateTimeInterface::ATOM),
            'paid_at' => $this->paidAt?->format(\DateTimeInterface::ATOM),
            'voided_at' => $this->voidedAt?->format(\DateTimeInterface::ATOM),
            'pdf_path' => $this->pdfPath,
        ];
    }
}
