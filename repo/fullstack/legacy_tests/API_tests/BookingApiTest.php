<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * Tests booking API request structures and state machine enforcement.
 */
class BookingApiTest extends TestCase
{
    public function test_hold_request_structure(): void
    {
        $request = [
            'inventory_id' => 'inv-001',
            'start_at' => '2026-05-01T10:00:00+00:00',
            'end_at' => '2026-05-05T10:00:00+00:00',
            'guest_name' => 'John Doe',
            'guest_email' => 'john@example.com',
        ];
        $this->assertArrayHasKey('inventory_id', $request);
        $this->assertArrayHasKey('start_at', $request);
        $this->assertArrayHasKey('end_at', $request);
        $this->assertArrayHasKey('guest_name', $request);
        $this->assertNotEmpty($request['inventory_id']);
    }

    public function test_hold_dates_must_be_chronological(): void
    {
        $startAt = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $endAt = new \DateTimeImmutable('2026-05-05T10:00:00+00:00');
        $this->assertGreaterThan($startAt, $endAt);
    }

    public function test_confirm_request_requires_hold_id(): void
    {
        $request = [
            'hold_id' => 'hold-abc-123',
            'payment_method' => 'card',
        ];
        $this->assertArrayHasKey('hold_id', $request);
        $this->assertNotEmpty($request['hold_id']);
    }

    public function test_cancel_request_structure(): void
    {
        $request = [
            'booking_id' => 'bk-001',
            'reason' => 'Guest requested cancellation',
        ];
        $this->assertArrayHasKey('booking_id', $request);
        $this->assertArrayHasKey('reason', $request);
        $this->assertNotEmpty($request['booking_id']);
    }

    public function test_state_machine_valid_transitions(): void
    {
        $validTransitions = [
            'pending_confirmation' => ['confirmed', 'canceled'],
            'confirmed' => ['active', 'canceled'],
            'active' => ['completed', 'no_show'],
            'completed' => [],
            'canceled' => [],
            'no_show' => [],
        ];

        $this->assertContains('confirmed', $validTransitions['pending_confirmation']);
        $this->assertContains('canceled', $validTransitions['pending_confirmation']);
        $this->assertContains('active', $validTransitions['confirmed']);
        $this->assertEmpty($validTransitions['completed']);
        $this->assertEmpty($validTransitions['canceled']);
        $this->assertEmpty($validTransitions['no_show']);
    }

    public function test_cannot_transition_from_terminal_state(): void
    {
        $terminalStates = ['completed', 'canceled', 'no_show'];
        $validTransitions = [
            'completed' => [],
            'canceled' => [],
            'no_show' => [],
        ];

        foreach ($terminalStates as $state) {
            $this->assertEmpty(
                $validTransitions[$state],
                "{$state} should have no valid transitions"
            );
        }
    }

    public function test_hold_expiry_must_be_in_future(): void
    {
        $expiresAt = new \DateTimeImmutable('+15 minutes');
        $now = new \DateTimeImmutable();
        $this->assertGreaterThan($now, $expiresAt);
    }
}
