<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\DTO\Request\UpdateSettingsRequest;
use PHPUnit\Framework\TestCase;

class DtoRequestsCoverageTest extends TestCase
{
    public function testUpdateSettingsRequestFromArrayAllFields(): void
    {
        $data = [
            'timezone' => 'America/New_York',
            'allow_partial_payments' => true,
            'cancellation_fee_pct' => '10.00',
            'no_show_fee_pct' => '25.00',
            'no_show_first_day_rent_enabled' => false,
            'hold_duration_minutes' => 20,
            'no_show_grace_period_minutes' => 15,
            'max_devices_per_user' => 5,
            'recurring_bill_day' => 1,
            'recurring_bill_hour' => 9,
            'booking_attempts_per_item_per_minute' => 30,
            'max_booking_duration_days' => 90,
            'terminals_enabled' => true,
        ];
        $req = UpdateSettingsRequest::fromArray($data);
        $this->assertSame('America/New_York', $req->timezone);
        $this->assertTrue($req->allow_partial_payments);
        $this->assertSame('10.00', $req->cancellation_fee_pct);
        $this->assertSame('25.00', $req->no_show_fee_pct);
        $this->assertFalse($req->no_show_first_day_rent_enabled);
        $this->assertSame(20, $req->hold_duration_minutes);
        $this->assertSame(15, $req->no_show_grace_period_minutes);
        $this->assertSame(5, $req->max_devices_per_user);
        $this->assertSame(1, $req->recurring_bill_day);
        $this->assertSame(9, $req->recurring_bill_hour);
        $this->assertSame(30, $req->booking_attempts_per_item_per_minute);
        $this->assertSame(90, $req->max_booking_duration_days);
        $this->assertTrue($req->terminals_enabled);
    }

    public function testUpdateSettingsRequestFromArrayEmpty(): void
    {
        $req = UpdateSettingsRequest::fromArray([]);
        $this->assertNull($req->timezone);
        $this->assertNull($req->allow_partial_payments);
        $this->assertNull($req->cancellation_fee_pct);
        $this->assertNull($req->hold_duration_minutes);
        $this->assertNull($req->terminals_enabled);
    }

    public function testUpdateSettingsRequestPartial(): void
    {
        $req = UpdateSettingsRequest::fromArray([
            'timezone' => 'UTC',
            'hold_duration_minutes' => 15,
        ]);
        $this->assertSame('UTC', $req->timezone);
        $this->assertSame(15, $req->hold_duration_minutes);
        $this->assertNull($req->allow_partial_payments);
    }
}
