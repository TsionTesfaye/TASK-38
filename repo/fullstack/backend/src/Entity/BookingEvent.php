<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\BookingEventType;
use App\Enum\BookingStatus;
use App\Repository\BookingEventRepository;

#[ORM\Entity(repositoryClass: BookingEventRepository::class)]
#[ORM\Table(name: 'booking_events')]
#[ORM\Index(columns: ['booking_id'], name: 'IDX_booking_events_booking')]
class BookingEvent implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Booking::class)]
    #[ORM\JoinColumn(name: 'booking_id', referencedColumnName: 'id', nullable: false)]
    private Booking $booking;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_user_id', referencedColumnName: 'id', nullable: false)]
    private User $actorUser;

    #[ORM\Column(name: 'event_type', type: 'string', length: 50, enumType: BookingEventType::class)]
    private BookingEventType $eventType;

    #[ORM\Column(name: 'before_status', type: 'string', length: 30, nullable: true, enumType: BookingStatus::class)]
    private ?BookingStatus $beforeStatus;

    #[ORM\Column(name: 'after_status', type: 'string', length: 30, nullable: true, enumType: BookingStatus::class)]
    private ?BookingStatus $afterStatus;

    #[ORM\Column(name: 'details_json', type: 'json', nullable: true)]
    private ?array $detailsJson;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, Booking $booking, User $actorUser, BookingEventType $eventType, ?BookingStatus $beforeStatus = null, ?BookingStatus $afterStatus = null, ?array $detailsJson = null)
    {
        $this->id = $id;
        $this->booking = $booking;
        $this->actorUser = $actorUser;
        $this->eventType = $eventType;
        $this->beforeStatus = $beforeStatus;
        $this->afterStatus = $afterStatus;
        $this->detailsJson = $detailsJson;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getBooking(): Booking { return $this->booking; }
    public function getActorUser(): User { return $this->actorUser; }
    public function getEventType(): BookingEventType { return $this->eventType; }
    public function getBeforeStatus(): ?BookingStatus { return $this->beforeStatus; }
    public function getAfterStatus(): ?BookingStatus { return $this->afterStatus; }
    public function getDetailsJson(): ?array { return $this->detailsJson; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking->getId(),
            'actor_user_id' => $this->actorUser->getId(),
            'event_type' => $this->eventType->value,
            'before_status' => $this->beforeStatus?->value,
            'after_status' => $this->afterStatus?->value,
            'details_json' => $this->detailsJson,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
