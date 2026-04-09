<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\Settings;

readonly class SettingsResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $timezone,
        public bool $allow_partial_payments,
        public string $cancellation_fee_pct,
        public string $no_show_fee_pct,
        public bool $no_show_first_day_rent_enabled,
        public int $hold_duration_minutes,
        public int $no_show_grace_period_minutes,
        public int $max_devices_per_user,
        public int $recurring_bill_day,
        public int $recurring_bill_hour,
        public int $booking_attempts_per_item_per_minute,
        public int $max_booking_duration_days,
        public bool $terminals_enabled,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromEntity(Settings $settings): self
    {
        return new self(
            id: $settings->getId(),
            organization_id: $settings->getOrganizationId(),
            timezone: $settings->getTimezone(),
            allow_partial_payments: $settings->getAllowPartialPayments(),
            cancellation_fee_pct: $settings->getCancellationFeePct(),
            no_show_fee_pct: $settings->getNoShowFeePct(),
            no_show_first_day_rent_enabled: $settings->getNoShowFirstDayRentEnabled(),
            hold_duration_minutes: $settings->getHoldDurationMinutes(),
            no_show_grace_period_minutes: $settings->getNoShowGracePeriodMinutes(),
            max_devices_per_user: $settings->getMaxDevicesPerUser(),
            recurring_bill_day: $settings->getRecurringBillDay(),
            recurring_bill_hour: $settings->getRecurringBillHour(),
            booking_attempts_per_item_per_minute: $settings->getBookingAttemptsPerItemPerMinute(),
            max_booking_duration_days: $settings->getMaxBookingDurationDays(),
            terminals_enabled: $settings->getTerminalsEnabled(),
            created_at: $settings->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updated_at: $settings->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'timezone' => $this->timezone,
            'allow_partial_payments' => $this->allow_partial_payments,
            'cancellation_fee_pct' => $this->cancellation_fee_pct,
            'no_show_fee_pct' => $this->no_show_fee_pct,
            'no_show_first_day_rent_enabled' => $this->no_show_first_day_rent_enabled,
            'hold_duration_minutes' => $this->hold_duration_minutes,
            'no_show_grace_period_minutes' => $this->no_show_grace_period_minutes,
            'max_devices_per_user' => $this->max_devices_per_user,
            'recurring_bill_day' => $this->recurring_bill_day,
            'recurring_bill_hour' => $this->recurring_bill_hour,
            'booking_attempts_per_item_per_minute' => $this->booking_attempts_per_item_per_minute,
            'max_booking_duration_days' => $this->max_booking_duration_days,
            'terminals_enabled' => $this->terminals_enabled,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
