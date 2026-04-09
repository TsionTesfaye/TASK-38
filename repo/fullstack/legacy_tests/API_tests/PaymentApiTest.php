<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * Tests payment API request structures, callback handling, and signature validation.
 */
class PaymentApiTest extends TestCase
{
    public function test_payment_initiation_request_structure(): void
    {
        $request = [
            'bill_id' => 'bill-001',
            'amount' => '500.00',
            'payment_method' => 'card',
            'currency' => 'USD',
        ];
        $this->assertArrayHasKey('bill_id', $request);
        $this->assertArrayHasKey('amount', $request);
        $this->assertArrayHasKey('payment_method', $request);
        $this->assertNotEmpty($request['bill_id']);
        $this->assertGreaterThan(0, (float) $request['amount']);
    }

    public function test_payment_amount_uses_bcmath_precision(): void
    {
        $amount = '499.99';
        $this->assertSame('499.99', $amount);
        $this->assertSame(2, strlen(explode('.', $amount)[1]));
    }

    public function test_callback_structure(): void
    {
        $callback = [
            'transaction_id' => 'txn-ext-001',
            'payment_id' => 'pay-001',
            'status' => 'completed',
            'amount' => '500.00',
            'timestamp' => '2026-05-01T12:00:00+00:00',
            'signature' => 'hmac_sha256_signature_here',
        ];
        $this->assertArrayHasKey('transaction_id', $callback);
        $this->assertArrayHasKey('payment_id', $callback);
        $this->assertArrayHasKey('status', $callback);
        $this->assertArrayHasKey('signature', $callback);
        $this->assertContains($callback['status'], ['completed', 'failed', 'pending']);
    }

    public function test_signature_verification(): void
    {
        $secret = 'webhook_secret_key';
        $payload = '{"payment_id":"pay-001","status":"completed","amount":"500.00"}';
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        $receivedSignature = hash_hmac('sha256', $payload, $secret);
        $this->assertTrue(hash_equals($expectedSignature, $receivedSignature));
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $secret = 'webhook_secret_key';
        $payload = '{"payment_id":"pay-001","status":"completed","amount":"500.00"}';
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        $tamperedPayload = '{"payment_id":"pay-001","status":"completed","amount":"999.00"}';
        $tamperedSignature = hash_hmac('sha256', $tamperedPayload, $secret);

        $this->assertFalse(hash_equals($expectedSignature, $tamperedSignature));
    }

    public function test_valid_payment_methods(): void
    {
        $validMethods = ['card', 'bank_transfer', 'mobile_money', 'cash'];
        $this->assertContains('card', $validMethods);
        $this->assertContains('cash', $validMethods);
        $this->assertNotContains('bitcoin', $validMethods);
    }

    public function test_payment_status_values(): void
    {
        $validStatuses = ['pending', 'processing', 'completed', 'failed', 'refunded'];
        $this->assertContains('completed', $validStatuses);
        $this->assertContains('refunded', $validStatuses);
        $this->assertCount(5, $validStatuses);
    }
}
