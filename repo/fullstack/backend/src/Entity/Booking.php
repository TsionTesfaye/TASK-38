<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\BookingStatus;
use App\Repository\BookingRepository;

#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Table(name: 'bookings')]
#[ORM\Index(columns: ['organization_id', 'tenant_user_id'], name: 'IDX_bookings_org_tenant')]
#[ORM\Index(columns: ['inventory_item_id', 'status', 'start_at', 'end_at'], name: 'IDX_bookings_item_status_dates')]
class Booking implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: InventoryItem::class)]
    #[ORM\JoinColumn(name: 'inventory_item_id', referencedColumnName: 'id', nullable: false)]
    private InventoryItem $inventoryItem;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'tenant_user_id', referencedColumnName: 'id', nullable: false)]
    private User $tenantUser;

    #[ORM\ManyToOne(targetEntity: BookingHold::class)]
    #[ORM\JoinColumn(name: 'source_hold_id', referencedColumnName: 'id', nullable: true)]
    private ?BookingHold $sourceHold;

    #[ORM\Column(type: 'string', length: 30, enumType: BookingStatus::class)]
    private BookingStatus $status = BookingStatus::CONFIRMED;

    #[ORM\Column(name: 'start_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(name: 'end_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $endAt;

    #[ORM\Column(name: 'booked_units', type: 'integer')]
    private int $bookedUnits;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(name: 'base_amount', type: 'decimal', precision: 12, scale: 2)]
    private string $baseAmount;

    #[ORM\Column(name: 'final_amount', type: 'decimal', precision: 12, scale: 2)]
    private string $finalAmount;

    #[ORM\Column(name: 'cancellation_fee_amount', type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $cancellationFeeAmount = '0.00';

    #[ORM\Column(name: 'no_show_penalty_amount', type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $noShowPenaltyAmount = '0.00';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'canceled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $canceledAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'no_show_marked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $noShowMarkedAt = null;

    #[ORM\Column(name: 'checked_in_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $checkedInAt = null;

    public function __construct(string $id, Organization $organization, InventoryItem $inventoryItem, User $tenantUser, ?BookingHold $sourceHold, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt, int $bookedUnits, string $currency, string $baseAmount, string $finalAmount)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->inventoryItem = $inventoryItem;
        $this->tenantUser = $tenantUser;
        $this->sourceHold = $sourceHold;
        $this->startAt = $startAt;
        $this->endAt = $endAt;
        $this->bookedUnits = $bookedUnits;
        $this->currency = $currency;
        $this->baseAmount = $baseAmount;
        $this->finalAmount = $finalAmount;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getInventoryItem(): InventoryItem { return $this->inventoryItem; }
    public function getInventoryItemId(): string { return $this->inventoryItem->getId(); }
    public function getTenantUser(): User { return $this->tenantUser; }
    public function getTenantUserId(): string { return $this->tenantUser->getId(); }
    public function getSourceHold(): ?BookingHold { return $this->sourceHold; }
    public function getStatus(): BookingStatus { return $this->status; }
    public function getStartAt(): \DateTimeImmutable { return $this->startAt; }
    public function setStartAt(\DateTimeImmutable $start): void { $this->startAt = $start; $this->updatedAt = new \DateTimeImmutable(); }
    public function getEndAt(): \DateTimeImmutable { return $this->endAt; }
    public function setEndAt(\DateTimeImmutable $end): void { $this->endAt = $end; $this->updatedAt = new \DateTimeImmutable(); }
    public function getBookedUnits(): int { return $this->bookedUnits; }
    public function getCurrency(): string { return $this->currency; }
    public function getBaseAmount(): string { return $this->baseAmount; }
    public function getFinalAmount(): string { return $this->finalAmount; }
    public function getCancellationFeeAmount(): string { return $this->cancellationFeeAmount; }
    public function getNoShowPenaltyAmount(): string { return $this->noShowPenaltyAmount; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getCanceledAt(): ?\DateTimeImmutable { return $this->canceledAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getNoShowMarkedAt(): ?\DateTimeImmutable { return $this->noShowMarkedAt; }
    public function getCheckedInAt(): ?\DateTimeImmutable { return $this->checkedInAt; }

    public function transitionTo(BookingStatus $newStatus): void
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \App\Exception\InvalidStateTransitionException($this->status->value, $newStatus->value);
        }
        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markCheckedIn(): void
    {
        $this->checkedInAt = new \DateTimeImmutable();
        $this->transitionTo(BookingStatus::ACTIVE);
    }

    public function markCanceled(string $feeAmount): void
    {
        $this->cancellationFeeAmount = $feeAmount;
        $this->canceledAt = new \DateTimeImmutable();
        $this->transitionTo(BookingStatus::CANCELED);
    }

    public function markCompleted(): void
    {
        $this->completedAt = new \DateTimeImmutable();
        $this->transitionTo(BookingStatus::COMPLETED);
    }

    public function markNoShow(string $penaltyAmount): void
    {
        $this->noShowPenaltyAmount = $penaltyAmount;
        $this->noShowMarkedAt = new \DateTimeImmutable();
        $this->transitionTo(BookingStatus::NO_SHOW);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'inventory_item_id' => $this->inventoryItem->getId(),
            'tenant_user_id' => $this->getTenantUserId(),
            'source_hold_id' => $this->sourceHold?->getId(),
            'status' => $this->status->value,
            'start_at' => $this->startAt->format(\DateTimeInterface::ATOM),
            'end_at' => $this->endAt->format(\DateTimeInterface::ATOM),
            'booked_units' => $this->bookedUnits,
            'currency' => $this->currency,
            'base_amount' => $this->baseAmount,
            'final_amount' => $this->finalAmount,
            'cancellation_fee_amount' => $this->cancellationFeeAmount,
            'no_show_penalty_amount' => $this->noShowPenaltyAmount,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
            'canceled_at' => $this->canceledAt?->format(\DateTimeInterface::ATOM),
            'completed_at' => $this->completedAt?->format(\DateTimeInterface::ATOM),
            'no_show_marked_at' => $this->noShowMarkedAt?->format(\DateTimeInterface::ATOM),
            'checked_in_at' => $this->checkedInAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
