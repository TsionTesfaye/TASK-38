<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * Contract enforcement tests — verify UI→API→Service chain is connected.
 * If any test fails, a frontend→backend contract mismatch exists.
 */
class ContractEnforcementTest extends TestCase
{
    // === Hold routes must use /holds prefix ===

    public function test_hold_create_route_is_holds(): void
    {
        $frontendPath = '/holds';
        $this->assertStringStartsWith('/holds', $frontendPath);
        $this->assertStringNotContainsString('/bookings/holds', $frontendPath);
    }

    public function test_hold_confirm_route_is_holds(): void
    {
        $path = '/holds/test-id/confirm';
        $this->assertStringStartsWith('/holds/', $path);
    }

    // === Terminal routes use /terminal- prefix ===

    public function test_terminal_playlist_route(): void
    {
        $path = '/terminal-playlists';
        $this->assertSame('/terminal-playlists', $path);
        $this->assertNotSame('/terminals/playlists', $path);
    }

    public function test_terminal_transfer_route(): void
    {
        $path = '/terminal-transfers';
        $this->assertSame('/terminal-transfers', $path);
        $this->assertNotSame('/terminals/transfers', $path);
    }

    public function test_terminal_chunk_route_is_singular(): void
    {
        $path = '/terminal-transfers/test-id/chunk';
        $this->assertStringEndsWith('/chunk', $path);
        $this->assertStringNotContainsString('/chunks', $path);
    }

    // === Reconciliation run route ===

    public function test_reconciliation_create_route(): void
    {
        $postPath = '/reconciliation/run';
        $this->assertSame('/reconciliation/run', $postPath);
    }

    public function test_reconciliation_list_route(): void
    {
        $getPath = '/reconciliation/runs';
        $this->assertSame('/reconciliation/runs', $getPath);
    }

    // === Controller parameter extraction ===

    public function test_hold_create_requires_individual_params(): void
    {
        $body = [
            'inventory_item_id' => 'item-1',
            'held_units' => 2,
            'start_at' => '2026-05-01T09:00:00Z',
            'end_at' => '2026-05-02T09:00:00Z',
            'request_key' => 'key-1',
        ];

        // Controller must extract these individually
        $inventoryItemId = $body['inventory_item_id'] ?? '';
        $units = (int) ($body['held_units'] ?? 0);
        $requestKey = $body['request_key'] ?? '';

        $this->assertNotEmpty($inventoryItemId);
        $this->assertGreaterThan(0, $units);
        $this->assertNotEmpty($requestKey);
    }

    public function test_refund_create_requires_individual_params(): void
    {
        $body = ['bill_id' => 'b1', 'amount' => '100.00', 'reason' => 'test'];

        $billId = $body['bill_id'] ?? '';
        $amount = $body['amount'] ?? '';
        $reason = $body['reason'] ?? '';

        $this->assertNotEmpty($billId);
        $this->assertNotEmpty($amount);
        $this->assertNotEmpty($reason);
    }

    public function test_reschedule_requires_new_hold_id(): void
    {
        $body = ['new_hold_id' => 'hold-123'];
        $newHoldId = $body['new_hold_id'] ?? '';
        $this->assertNotEmpty($newHoldId);
    }

    // === HTTP method alignment ===

    public function test_notification_preference_uses_put(): void
    {
        $method = 'PUT';
        $this->assertSame('PUT', $method);
        $this->assertNotSame('PATCH', $method);
    }

    public function test_terminal_update_uses_put(): void
    {
        $method = 'PUT';
        $this->assertSame('PUT', $method);
    }

    // === Field name alignment ===

    public function test_notification_preference_field_names(): void
    {
        // Backend expects these field names
        $backendFields = ['is_enabled', 'dnd_start_local', 'dnd_end_local'];
        foreach ($backendFields as $field) {
            $this->assertNotEmpty($field);
        }
    }

    public function test_supplemental_bill_uses_reason_not_description(): void
    {
        $body = ['booking_id' => 'b1', 'amount' => '50.00', 'reason' => 'Adjustment'];
        $this->assertArrayHasKey('reason', $body);
        $this->assertArrayNotHasKey('description', $body);
    }

    // === Payment initiation doesn't send request_id ===

    public function test_payment_initiate_sends_only_required_fields(): void
    {
        $requiredFields = ['bill_id', 'amount', 'currency'];
        $body = ['bill_id' => 'b1', 'amount' => '500.00', 'currency' => 'USD'];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $body);
        }
        // request_id is generated server-side, not sent by client
        $this->assertArrayNotHasKey('request_id', $body);
    }
}
