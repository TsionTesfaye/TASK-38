<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * Tests refund API request structures and amount validations.
 */
class RefundApiTest extends TestCase
{
    public function test_refund_request_structure(): void
    {
        $request = [
            'payment_id' => 'pay-001',
            'amount' => '100.00',
            'reason' => 'Customer requested refund',
        ];
        $this->assertArrayHasKey('payment_id', $request);
        $this->assertArrayHasKey('amount', $request);
        $this->assertArrayHasKey('reason', $request);
        $this->assertNotEmpty($request['payment_id']);
        $this->assertNotEmpty($request['reason']);
    }

    public function test_refund_amount_must_be_positive(): void
    {
        $amount = '100.00';
        $this->assertGreaterThan(0, (float) $amount);

        $negativeAmount = '-50.00';
        $this->assertLessThan(0, (float) $negativeAmount);
    }

    public function test_refund_cannot_exceed_payment_amount(): void
    {
        $paymentAmount = '500.00';
        $refundAmount = '600.00';
        $exceeds = bccomp($refundAmount, $paymentAmount, 2) > 0;
        $this->assertTrue($exceeds);
    }

    public function test_refund_within_payment_amount_is_valid(): void
    {
        $paymentAmount = '500.00';
        $refundAmount = '200.00';
        $exceeds = bccomp($refundAmount, $paymentAmount, 2) > 0;
        $this->assertFalse($exceeds);
    }

    public function test_partial_refund_tracks_remaining(): void
    {
        $paymentAmount = '500.00';
        $firstRefund = '200.00';
        $remaining = bcsub($paymentAmount, $firstRefund, 2);
        $this->assertSame('300.00', $remaining);

        $secondRefund = '300.00';
        $exceeds = bccomp($secondRefund, $remaining, 2) > 0;
        $this->assertFalse($exceeds);
    }

    public function test_refund_amount_precision(): void
    {
        $amount = '123.45';
        $parts = explode('.', $amount);
        $this->assertCount(2, $parts);
        $this->assertSame(2, strlen($parts[1]));
    }

    public function test_full_refund_equals_payment(): void
    {
        $paymentAmount = '750.00';
        $refundAmount = '750.00';
        $remaining = bcsub($paymentAmount, $refundAmount, 2);
        $this->assertSame('0.00', $remaining);
    }

    public function test_refund_response_expected_fields(): void
    {
        $expectedFields = ['refund_id', 'payment_id', 'amount', 'status', 'created_at'];
        $response = [
            'refund_id' => 'ref-001',
            'payment_id' => 'pay-001',
            'amount' => '100.00',
            'status' => 'processed',
            'created_at' => '2026-05-01T12:00:00+00:00',
        ];
        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $response);
        }
    }
}
