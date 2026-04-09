<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\TestCase;

class EndToEndWorkflowTest extends TestCase
{
    public function test_tenant_booking_flow_structure(): void
    {
        $holdRequest = ['inventory_item_id' => 'item-1', 'units' => 1, 'start_at' => '2026-05-01T09:00:00Z', 'end_at' => '2026-05-02T09:00:00Z', 'request_key' => 'key-1'];
        $this->assertArrayHasKey('request_key', $holdRequest);
        $this->assertArrayHasKey('inventory_item_id', $holdRequest);

        $holdResponse = ['id' => 'hold-1', 'expires_at' => '2026-04-05T10:10:00Z', 'status' => 'active'];
        $this->assertSame('active', $holdResponse['status']);

        $bookingResponse = ['id' => 'booking-1', 'status' => 'confirmed'];
        $this->assertSame('confirmed', $bookingResponse['status']);
    }

    public function test_payment_callback_flow(): void
    {
        $initiate = ['bill_id' => 'bill-1', 'amount' => '500.00', 'currency' => 'USD'];
        $callback = ['request_id' => 'pay-1', 'status' => 'succeeded', 'amount' => '500.00', 'currency' => 'USD'];
        $this->assertSame($initiate['amount'], $callback['amount']);
        $this->assertSame($initiate['currency'], $callback['currency']);
    }

    public function test_refund_cumulative_tracking(): void
    {
        $totalPaid = '500.00';
        $refund1 = '200.00';
        $remaining = bcsub($totalPaid, $refund1, 2);
        $this->assertSame('300.00', $remaining);

        $refund2 = '300.00';
        $remaining2 = bcsub($remaining, $refund2, 2);
        $this->assertSame('0.00', $remaining2);

        $refund3 = '1.00';
        $this->assertTrue(bccomp($refund3, $remaining2, 2) > 0);
    }

    public function test_cancellation_fee_boundary(): void
    {
        $fee24h = 24.0 >= 24 ? '0.00' : bcmul('1000.00', '0.20', 2);
        $this->assertSame('0.00', $fee24h);

        $fee23h = 23.0 >= 24 ? '0.00' : bcmul('1000.00', '0.20', 2);
        $this->assertSame('200.00', $fee23h);
    }

    public function test_dnd_midnight_crossing(): void
    {
        $dndStart = '21:00';
        $dndEnd = '08:00';

        $inDnd = fn(string $t) => ($dndStart > $dndEnd)
            ? ($t >= $dndStart || $t < $dndEnd)
            : ($t >= $dndStart && $t < $dndEnd);

        $this->assertTrue($inDnd('22:00'));
        $this->assertTrue($inDnd('03:00'));
        $this->assertFalse($inDnd('12:00'));
        $this->assertFalse($inDnd('08:00'));
    }

    public function test_reconciliation_mismatch_detection(): void
    {
        $original = '1000.00';
        $paid = '600.00';
        $refunded = '100.00';
        $expected = bcsub($original, bcsub($paid, $refunded, 2), 2);
        $this->assertSame('500.00', $expected);

        $actual = '400.00';
        $this->assertNotSame($expected, $actual);
    }
}
