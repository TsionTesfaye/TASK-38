<?php

declare(strict_types=1);

namespace App\DTO\Request;

readonly class UpdateSettingsRequest
{
    public function __construct(
        public ?string $timezone = null,
        public ?bool $allow_partial_payments = null,
        public ?string $cancellation_fee_pct = null,
        public ?string $no_show_fee_pct = null,
        public ?bool $no_show_first_day_rent_enabled = null,
        public ?int $hold_duration_minutes = null,
        public ?int $no_show_grace_period_minutes = null,
        public ?int $max_devices_per_user = null,
        public ?int $recurring_bill_day = null,
        public ?int $recurring_bill_hour = null,
        public ?int $booking_attempts_per_item_per_minute = null,
        public ?int $max_booking_duration_days = null,
        public ?bool $terminals_enabled = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            timezone: $data['timezone'] ?? null,
            allow_partial_payments: isset($data['allow_partial_payments']) ? (bool) $data['allow_partial_payments'] : null,
            cancellation_fee_pct: $data['cancellation_fee_pct'] ?? null,
            no_show_fee_pct: $data['no_show_fee_pct'] ?? null,
            no_show_first_day_rent_enabled: isset($data['no_show_first_day_rent_enabled']) ? (bool) $data['no_show_first_day_rent_enabled'] : null,
            hold_duration_minutes: isset($data['hold_duration_minutes']) ? (int) $data['hold_duration_minutes'] : null,
            no_show_grace_period_minutes: isset($data['no_show_grace_period_minutes']) ? (int) $data['no_show_grace_period_minutes'] : null,
            max_devices_per_user: isset($data['max_devices_per_user']) ? (int) $data['max_devices_per_user'] : null,
            recurring_bill_day: isset($data['recurring_bill_day']) ? (int) $data['recurring_bill_day'] : null,
            recurring_bill_hour: isset($data['recurring_bill_hour']) ? (int) $data['recurring_bill_hour'] : null,
            booking_attempts_per_item_per_minute: isset($data['booking_attempts_per_item_per_minute']) ? (int) $data['booking_attempts_per_item_per_minute'] : null,
            max_booking_duration_days: isset($data['max_booking_duration_days']) ? (int) $data['max_booking_duration_days'] : null,
            terminals_enabled: isset($data['terminals_enabled']) ? (bool) $data['terminals_enabled'] : null,
        );
    }
}
