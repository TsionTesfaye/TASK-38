<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\SettingsRepository;

#[ORM\Entity(repositoryClass: SettingsRepository::class)]
#[ORM\Table(name: 'settings')]
#[ORM\UniqueConstraint(name: 'UNIQ_settings_organization', columns: ['organization_id'])]
class Settings implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\Column(type: 'string', length: 100, options: ['default' => 'UTC'])]
    private string $timezone = 'UTC';

    #[ORM\Column(name: 'allow_partial_payments', type: 'boolean', options: ['default' => false])]
    private bool $allowPartialPayments = false;

    #[ORM\Column(name: 'cancellation_fee_pct', type: 'decimal', precision: 5, scale: 2, options: ['default' => '20.00'])]
    private string $cancellationFeePct = '20.00';

    #[ORM\Column(name: 'no_show_fee_pct', type: 'decimal', precision: 5, scale: 2, options: ['default' => '50.00'])]
    private string $noShowFeePct = '50.00';

    #[ORM\Column(name: 'no_show_first_day_rent_enabled', type: 'boolean', options: ['default' => true])]
    private bool $noShowFirstDayRentEnabled = true;

    #[ORM\Column(name: 'hold_duration_minutes', type: 'integer', options: ['default' => 10])]
    private int $holdDurationMinutes = 10;

    #[ORM\Column(name: 'no_show_grace_period_minutes', type: 'integer', options: ['default' => 30])]
    private int $noShowGracePeriodMinutes = 30;

    #[ORM\Column(name: 'max_devices_per_user', type: 'integer', options: ['default' => 5])]
    private int $maxDevicesPerUser = 5;

    #[ORM\Column(name: 'recurring_bill_day', type: 'integer', options: ['default' => 1])]
    private int $recurringBillDay = 1;

    #[ORM\Column(name: 'recurring_bill_hour', type: 'integer', options: ['default' => 9])]
    private int $recurringBillHour = 9;

    #[ORM\Column(name: 'booking_attempts_per_item_per_minute', type: 'integer', options: ['default' => 30])]
    private int $bookingAttemptsPerItemPerMinute = 30;

    #[ORM\Column(name: 'max_booking_duration_days', type: 'integer', options: ['default' => 365])]
    private int $maxBookingDurationDays = 365;

    #[ORM\Column(name: 'terminals_enabled', type: 'boolean', options: ['default' => false])]
    private bool $terminalsEnabled = false;

    #[ORM\Column(name: 'notification_templates', type: 'json', options: ['default' => '{}'])]
    private array $notificationTemplates = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Organization $organization)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getTimezone(): string { return $this->timezone; }
    public function setTimezone(string $tz): void { $this->timezone = $tz; $this->updatedAt = new \DateTimeImmutable(); }
    public function getAllowPartialPayments(): bool { return $this->allowPartialPayments; }
    public function setAllowPartialPayments(bool $v): void { $this->allowPartialPayments = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getCancellationFeePct(): string { return $this->cancellationFeePct; }
    public function setCancellationFeePct(string $v): void { $this->cancellationFeePct = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getNoShowFeePct(): string { return $this->noShowFeePct; }
    public function setNoShowFeePct(string $v): void { $this->noShowFeePct = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getNoShowFirstDayRentEnabled(): bool { return $this->noShowFirstDayRentEnabled; }
    public function setNoShowFirstDayRentEnabled(bool $v): void { $this->noShowFirstDayRentEnabled = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getHoldDurationMinutes(): int { return $this->holdDurationMinutes; }
    public function setHoldDurationMinutes(int $v): void { $this->holdDurationMinutes = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getNoShowGracePeriodMinutes(): int { return $this->noShowGracePeriodMinutes; }
    public function setNoShowGracePeriodMinutes(int $v): void { $this->noShowGracePeriodMinutes = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getMaxDevicesPerUser(): int { return $this->maxDevicesPerUser; }
    public function setMaxDevicesPerUser(int $v): void { $this->maxDevicesPerUser = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getRecurringBillDay(): int { return $this->recurringBillDay; }
    public function setRecurringBillDay(int $v): void { $this->recurringBillDay = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getRecurringBillHour(): int { return $this->recurringBillHour; }
    public function setRecurringBillHour(int $v): void { $this->recurringBillHour = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getBookingAttemptsPerItemPerMinute(): int { return $this->bookingAttemptsPerItemPerMinute; }
    public function setBookingAttemptsPerItemPerMinute(int $v): void { $this->bookingAttemptsPerItemPerMinute = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getMaxBookingDurationDays(): int { return $this->maxBookingDurationDays; }
    public function setMaxBookingDurationDays(int $v): void { $this->maxBookingDurationDays = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getTerminalsEnabled(): bool { return $this->terminalsEnabled; }
    public function setTerminalsEnabled(bool $v): void { $this->terminalsEnabled = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getNotificationTemplates(): array { return $this->notificationTemplates; }
    public function setNotificationTemplates(array $v): void { $this->notificationTemplates = $v; $this->updatedAt = new \DateTimeImmutable(); }
    public function getNotificationTemplate(string $eventCode): ?string { return $this->notificationTemplates[$eventCode] ?? null; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'timezone' => $this->timezone,
            'allow_partial_payments' => $this->allowPartialPayments,
            'cancellation_fee_pct' => (string) $this->cancellationFeePct,
            'no_show_fee_pct' => (string) $this->noShowFeePct,
            'no_show_first_day_rent_enabled' => $this->noShowFirstDayRentEnabled,
            'hold_duration_minutes' => $this->holdDurationMinutes,
            'no_show_grace_period_minutes' => $this->noShowGracePeriodMinutes,
            'max_devices_per_user' => $this->maxDevicesPerUser,
            'recurring_bill_day' => $this->recurringBillDay,
            'recurring_bill_hour' => $this->recurringBillHour,
            'booking_attempts_per_item_per_minute' => $this->bookingAttemptsPerItemPerMinute,
            'max_booking_duration_days' => $this->maxBookingDurationDays,
            'terminals_enabled' => $this->terminalsEnabled,
            'notification_templates' => $this->notificationTemplates,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
