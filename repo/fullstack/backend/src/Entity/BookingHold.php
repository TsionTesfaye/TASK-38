<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\BookingHoldStatus;
use App\Repository\BookingHoldRepository;

#[ORM\Entity(repositoryClass: BookingHoldRepository::class)]
#[ORM\Table(name: 'booking_holds')]
#[ORM\UniqueConstraint(name: 'UNIQ_booking_holds_tenant_request', columns: ['tenant_user_id', 'request_key'])]
#[ORM\Index(columns: ['inventory_item_id', 'status'], name: 'IDX_booking_holds_item_status')]
#[ORM\Index(columns: ['expires_at', 'status'], name: 'IDX_booking_holds_expires_status')]
class BookingHold implements \JsonSerializable
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

    #[ORM\Column(name: 'request_key', type: 'string', length: 255)]
    private string $requestKey;

    #[ORM\Column(name: 'held_units', type: 'integer')]
    private int $heldUnits;

    #[ORM\Column(name: 'start_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(name: 'end_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $endAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'string', length: 30, enumType: BookingHoldStatus::class)]
    private BookingHoldStatus $status = BookingHoldStatus::ACTIVE;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'confirmed_booking_id', type: 'string', length: 36, nullable: true)]
    private ?string $confirmedBookingId = null;

    public function __construct(string $id, Organization $organization, InventoryItem $inventoryItem, User $tenantUser, string $requestKey, int $heldUnits, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt, \DateTimeImmutable $expiresAt)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->inventoryItem = $inventoryItem;
        $this->tenantUser = $tenantUser;
        $this->requestKey = $requestKey;
        $this->heldUnits = $heldUnits;
        $this->startAt = $startAt;
        $this->endAt = $endAt;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getInventoryItem(): InventoryItem { return $this->inventoryItem; }
    public function getInventoryItemId(): string { return $this->inventoryItem->getId(); }
    public function getTenantUser(): User { return $this->tenantUser; }
    public function getTenantUserId(): string { return $this->tenantUser->getId(); }
    public function getRequestKey(): string { return $this->requestKey; }
    public function getHeldUnits(): int { return $this->heldUnits; }
    public function getStartAt(): \DateTimeImmutable { return $this->startAt; }
    public function getEndAt(): \DateTimeImmutable { return $this->endAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getStatus(): BookingHoldStatus { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getConfirmedBookingId(): ?string { return $this->confirmedBookingId; }

    public function transitionTo(BookingHoldStatus $newStatus): void
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \App\Exception\InvalidStateTransitionException($this->status->value, $newStatus->value);
        }
        $this->status = $newStatus;
    }

    public function setConfirmedBookingId(string $bookingId): void
    {
        $this->confirmedBookingId = $bookingId;
    }

    public function isExpiredByTime(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'inventory_item_id' => $this->getInventoryItemId(),
            'tenant_user_id' => $this->getTenantUserId(),
            'request_key' => $this->requestKey,
            'held_units' => $this->heldUnits,
            'start_at' => $this->startAt->format(\DateTimeInterface::ATOM),
            'end_at' => $this->endAt->format(\DateTimeInterface::ATOM),
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::ATOM),
            'status' => $this->status->value,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'confirmed_booking_id' => $this->confirmedBookingId,
        ];
    }
}
