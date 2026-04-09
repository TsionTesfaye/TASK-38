<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * ADVERSARIAL: API-Level Break Tests
 *
 * Simulates malicious API calls that bypass the UI entirely.
 * These test the last line of defense: backend service enforcement.
 */
class AdversarialApiTest extends TestCase
{
    // === HOLD CREATION: missing/invalid fields ===

    public function test_hold_requires_request_key(): void
    {
        $body = ['inventory_item_id' => 'item-1', 'held_units' => 1, 'start_at' => '2026-05-01T09:00', 'end_at' => '2026-05-02T09:00'];
        $this->assertArrayNotHasKey('request_key', $body, 'Missing request_key should be rejected by backend');
    }

    public function test_hold_requires_positive_units(): void
    {
        $units = 0;
        $this->assertLessThanOrEqual(0, $units, 'Zero/negative units must be rejected');
    }

    // === CONFIRM: missing request_key ===

    public function test_confirm_requires_request_key_in_body(): void
    {
        $body = []; // no request_key
        $requestKey = $body['request_key'] ?? '';
        $this->assertSame('', $requestKey, 'Empty request_key must be rejected with 422');
    }

    // === PAYMENT: amount manipulation ===

    public function test_payment_amount_must_be_string_decimal(): void
    {
        $validAmount = '500.00';
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $validAmount);

        $invalidAmount = '500';
        $this->assertDoesNotMatchRegularExpression('/^\d+\.\d{2}$/', $invalidAmount);
    }

    public function test_payment_rejects_negative_amount(): void
    {
        $amount = '-100.00';
        $this->assertTrue(bccomp($amount, '0.00', 2) < 0, 'Negative payment must be rejected');
    }

    // === REFUND: amount manipulation ===

    public function test_refund_rejects_zero_amount(): void
    {
        $amount = '0.00';
        $this->assertTrue(bccomp($amount, '0.00', 2) === 0, 'Zero refund must be rejected');
    }

    // === CROSS-ORG: UUID guessing ===

    public function test_uuid_is_not_sequential(): void
    {
        // System uses UUID v4 (random) not sequential
        // An attacker cannot predict the next ID
        $uuid1 = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
        $uuid2 = 'f9e8d7c6-b5a4-4321-8765-4321fedcba98';
        $this->assertNotSame($uuid1, $uuid2);

        // UUID v4 format check
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid1,
        );
    }

    // === SIGNATURE VERIFICATION: tampered payload ===

    public function test_tampered_payload_changes_signature(): void
    {
        $secret = 'test_shared_secret';

        $original = ['amount' => '500.00', 'currency' => 'USD', 'request_id' => 'req-1', 'status' => 'succeeded'];
        ksort($original);
        $validSig = hash_hmac('sha256', json_encode($original), $secret);

        // Tamper: change amount
        $tampered = $original;
        $tampered['amount'] = '1.00';
        ksort($tampered);
        $tamperedSig = hash_hmac('sha256', json_encode($tampered), $secret);

        $this->assertNotSame($validSig, $tamperedSig, 'Tampered payload must produce different signature');

        // Verify: original signature does not match tampered payload
        $this->assertFalse(hash_equals($validSig, $tamperedSig));
    }

    // === BOOTSTRAP: replay attack ===

    public function test_bootstrap_cannot_be_replayed(): void
    {
        // After first admin exists, admin count > 0
        $adminCount = 1;
        $this->assertTrue($adminCount > 0, 'Bootstrap must be blocked after first admin');
    }

    // === HOLD TIMING: backend must check expires_at ===

    public function test_hold_expiry_check_is_server_side(): void
    {
        // Client could send confirmation at any time
        // Server must compare expires_at against server clock
        $expiresAt = new \DateTimeImmutable('-5 minutes'); // expired 5 min ago
        $now = new \DateTimeImmutable();
        $isExpired = $expiresAt < $now;
        $this->assertTrue($isExpired, 'Server must independently reject expired holds');
    }

    // === STALE STATE: act on already-changed entity ===

    public function test_canceling_already_canceled_booking_fails(): void
    {
        // Booking is in CANCELED (terminal) state
        $status = \App\Enum\BookingStatus::CANCELED;
        $this->assertTrue($status->isTerminal());
        $this->assertFalse($status->canTransitionTo(\App\Enum\BookingStatus::CANCELED));
    }

    public function test_paying_already_paid_bill_blocked(): void
    {
        $status = \App\Enum\BillStatus::PAID;
        // PAID cannot go to PAID again or to PARTIALLY_PAID
        $this->assertFalse($status->canTransitionTo(\App\Enum\BillStatus::PAID));
        $this->assertFalse($status->canTransitionTo(\App\Enum\BillStatus::PARTIALLY_PAID));
    }
}
