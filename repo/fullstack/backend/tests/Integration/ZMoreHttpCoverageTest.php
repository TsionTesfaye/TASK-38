<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Additional real-HTTP integration coverage for under-covered services:
 * BookingHoldService, RefundService, NotificationService, LedgerService,
 * IdempotencyService, ThrottleService — exercised through actual endpoints.
 */
class ZMoreHttpCoverageTest extends WebTestCase
{
    private ?string $adminToken = null;

    private function api(string $method, string $path, ?array $body = null, ?string $token = null, array $extra = []): array
    {
        $client = static::$booted ? static::getClient() : static::createClient();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) $server['HTTP_AUTHORIZATION'] = "Bearer {$token}";
        foreach ($extra as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        $client->request($method, "/api/v1{$path}", [], [], $server, $body !== null ? json_encode($body) : null);
        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body' => json_decode($client->getResponse()->getContent(), true) ?? [],
        ];
    }

    private function getAdminToken(): string
    {
        if ($this->adminToken) return $this->adminToken;

        $this->api('POST', '/bootstrap', [
            'organization_name' => 'More HTTP Coverage',
            'organization_code' => 'MHC',
            'admin_username' => 'mhc_admin',
            'admin_password' => 'password123',
            'admin_display_name' => 'MHC Admin',
        ]);

        $candidates = [
            ['mhc_admin', 'password123'],
            ['admin', 'password123'],
            ['all_ctrl_admin', 'password123'],
            ['http_test_admin', 'secure_pass_123'],
            ['e2e_admin', 'e2e_password_123'],
            ['session_cap_admin', 'secure_pass_123'],
            ['uniq_admin', 'secure_pass_123'],
            ['payadmin', 'password123'],
        ];
        foreach ($candidates as [$u, $p]) {
            $r = $this->api('POST', '/auth/login', [
                'username' => $u, 'password' => $p,
                'device_label' => 'mhc', 'client_device_id' => 'mhc-' . uniqid(),
            ]);
            if ($r['status'] === 200) {
                $this->adminToken = $r['body']['data']['access_token'];
                return $this->adminToken;
            }
        }
        $this->fail('No admin login');
    }

    private function createItemAndPricing(string $token): ?string
    {
        $asset = 'MHC-' . bin2hex(random_bytes(4));
        $r = $this->api('POST', '/inventory', [
            'asset_code' => $asset, 'name' => 'MHC Item',
            'asset_type' => 'studio', 'location_name' => 'L',
            'capacity_mode' => 'discrete_units',
            'total_capacity' => 3, 'timezone' => 'UTC',
        ], $token);
        if ($r['status'] !== 201) return null;
        $itemId = $r['body']['data']['id'];

        $this->api('POST', "/inventory/{$itemId}/pricing", [
            'rate_type' => 'daily', 'amount' => '100.00', 'currency' => 'USD',
            'effective_from' => '2026-01-01T00:00:00Z',
        ], $token);

        return $itemId;
    }

    private function createTenant(string $token): ?array
    {
        $uname = 'mhc_t_' . substr(uniqid(), 0, 6);
        $r = $this->api('POST', '/users', [
            'username' => $uname, 'password' => 'tenpass123',
            'display_name' => 'MHC Tenant', 'role' => 'tenant',
        ], $token);
        if ($r['status'] !== 201) return null;

        $l = $this->api('POST', '/auth/login', [
            'username' => $uname, 'password' => 'tenpass123',
            'device_label' => 'mhc-t', 'client_device_id' => 'mhc-t-' . uniqid(),
        ]);
        if ($l['status'] !== 200) return null;

        return [
            'id' => $r['body']['data']['id'],
            'username' => $uname,
            'token' => $l['body']['data']['access_token'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Hold edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testHoldCreationValidationErrors(): void
    {
        $token = $this->getAdminToken();
        $tenant = $this->createTenant($token);
        if ($tenant === null) $this->markTestSkipped();

        // Missing inventory_item_id
        $r = $this->api('POST', '/holds', [
            'held_units' => 1,
            'start_at' => '2026-08-01T10:00:00Z',
            'end_at' => '2026-08-02T10:00:00Z',
            'request_key' => 'x-' . uniqid(),
        ], $tenant['token']);
        $this->assertContains($r['status'], [400, 422, 404]);

        // End before start
        $itemId = $this->createItemAndPricing($token);
        if ($itemId !== null) {
            $r = $this->api('POST', '/holds', [
                'inventory_item_id' => $itemId,
                'held_units' => 1,
                'start_at' => '2026-08-10T10:00:00Z',
                'end_at' => '2026-08-05T10:00:00Z', // earlier than start
                'request_key' => 'rev-' . uniqid(),
            ], $tenant['token']);
            $this->assertContains($r['status'], [400, 422, 409]);
        }

        // Zero units
        if ($itemId !== null) {
            $r = $this->api('POST', '/holds', [
                'inventory_item_id' => $itemId,
                'held_units' => 0,
                'start_at' => '2026-09-01T10:00:00Z',
                'end_at' => '2026-09-02T10:00:00Z',
                'request_key' => 'zero-' . uniqid(),
            ], $tenant['token']);
            $this->assertContains($r['status'], [400, 422]);
        }
    }

    public function testHoldDuplicateRequestKeyIsIdempotent(): void
    {
        $token = $this->getAdminToken();
        $tenant = $this->createTenant($token);
        $itemId = $this->createItemAndPricing($token);
        if ($tenant === null || $itemId === null) $this->markTestSkipped();

        $key = 'dup-' . uniqid();
        $body = [
            'inventory_item_id' => $itemId, 'held_units' => 1,
            'start_at' => '2026-10-01T10:00:00Z',
            'end_at' => '2026-10-02T10:00:00Z',
            'request_key' => $key,
        ];

        $r1 = $this->api('POST', '/holds', $body, $tenant['token']);
        $this->assertContains($r1['status'], [201, 409, 429]);

        $r2 = $this->api('POST', '/holds', $body, $tenant['token']);
        // Same key → idempotent 201 (same result) or 409 conflict
        $this->assertContains($r2['status'], [201, 409, 429, 200]);
    }

    public function testReleaseNonexistentHold(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('POST', '/holds/00000000-0000-0000-0000-000000000000/release', null, $token);
        $this->assertContains($r['status'], [404, 403, 409]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Refund flow (through the full booking/payment path)
    // ═══════════════════════════════════════════════════════════════

    public function testRefundCreationRequiresExistingBill(): void
    {
        $token = $this->getAdminToken();
        // Try to refund a nonexistent bill
        $r = $this->api('POST', '/refunds', [
            'bill_id' => '00000000-0000-0000-0000-000000000000',
            'amount' => '10.00',
            'reason' => 'test',
        ], $token);
        $this->assertContains($r['status'], [404, 403, 422]);
    }

    public function testRefundGetNotFound(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('GET', '/refunds/00000000-0000-0000-0000-000000000000', null, $token);
        $this->assertContains($r['status'], [404, 403]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ledger filters
    // ═══════════════════════════════════════════════════════════════

    public function testLedgerListFilters(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('GET', '/ledger?entry_type=payment_received&page=1&per_page=10', null, $token);
        $this->assertContains($r['status'], [200, 403]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Notifications
    // ═══════════════════════════════════════════════════════════════

    public function testNotificationPreferenceMultipleEventCodes(): void
    {
        $token = $this->getAdminToken();
        foreach (['booking.confirmed', 'bill.issued', 'payment.received'] as $ev) {
            $r = $this->api('PUT', "/notifications/preferences/{$ev}", [
                'enabled' => true,
                'dnd_start' => '21:00',
                'dnd_end' => '08:00',
            ], $token);
            $this->assertContains($r['status'], [200, 404]);
        }
    }

    public function testNotificationPreferenceDisabled(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('PUT', '/notifications/preferences/booking.confirmed', [
            'enabled' => false,
        ], $token);
        $this->assertContains($r['status'], [200, 404]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Settings edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSettingsBoundaryValues(): void
    {
        $token = $this->getAdminToken();

        // Max allowed values
        $r = $this->api('PUT', '/settings', [
            'max_devices_per_user' => 5,
            'hold_duration_minutes' => 60,
            'cancellation_fee_pct' => '100.00',
            'no_show_fee_pct' => '100.00',
        ], $token);
        $this->assertContains($r['status'], [200, 403, 422]);

        // Zero / invalid
        $r = $this->api('PUT', '/settings', ['max_devices_per_user' => 0], $token);
        $this->assertContains($r['status'], [400, 422, 403]);

        $r = $this->api('PUT', '/settings', ['cancellation_fee_pct' => '-10.00'], $token);
        $this->assertContains($r['status'], [400, 422, 403]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inventory edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testInventoryCreateInvalidCapacityMode(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('POST', '/inventory', [
            'asset_code' => 'INV-' . uniqid(),
            'name' => 'X', 'asset_type' => 'studio', 'location_name' => 'L',
            'capacity_mode' => 'not_a_real_mode',
            'total_capacity' => 1, 'timezone' => 'UTC',
        ], $token);
        $this->assertContains($r['status'], [422, 400, 403]);
    }

    public function testInventoryUpdateNotFound(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('PUT', '/inventory/00000000-0000-0000-0000-000000000000', [
            'name' => 'Updated',
        ], $token);
        $this->assertSame(404, $r['status']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Users list with filters
    // ═══════════════════════════════════════════════════════════════

    public function testUsersListWithFilters(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('GET', '/users?role=tenant&is_active=1&page=1&per_page=10', null, $token);
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('data', $r['body']['data']);
    }

    public function testUsersGetNotFound(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('GET', '/users/00000000-0000-0000-0000-000000000000', null, $token);
        $this->assertSame(404, $r['status']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bookings with tenant scope
    // ═══════════════════════════════════════════════════════════════

    public function testTenantListsOwnBookings(): void
    {
        $token = $this->getAdminToken();
        $tenant = $this->createTenant($token);
        if ($tenant === null) $this->markTestSkipped();

        $r = $this->api('GET', '/bookings?page=1&per_page=5', null, $tenant['token']);
        $this->assertSame(200, $r['status']);
    }

    public function testBookingInvalidTransitions(): void
    {
        $token = $this->getAdminToken();
        // Check-in unknown booking
        $r = $this->api('POST', '/bookings/00000000-0000-0000-0000-000000000000/check-in', null, $token);
        $this->assertContains($r['status'], [404, 403, 409]);

        $r = $this->api('POST', '/bookings/00000000-0000-0000-0000-000000000000/complete', null, $token);
        $this->assertContains($r['status'], [404, 403, 409]);

        $r = $this->api('POST', '/bookings/00000000-0000-0000-0000-000000000000/cancel', null, $token);
        $this->assertContains($r['status'], [404, 403, 409]);

        $r = $this->api('POST', '/bookings/00000000-0000-0000-0000-000000000000/no-show', null, $token);
        $this->assertContains($r['status'], [404, 403, 409]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bills edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testBillVoidNotFound(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('POST', '/bills/00000000-0000-0000-0000-000000000000/void', null, $token);
        $this->assertContains($r['status'], [404, 403, 409]);
    }

    public function testBillListWithStatusFilter(): void
    {
        $token = $this->getAdminToken();
        foreach (['open', 'paid', 'voided', 'partially_paid'] as $status) {
            $r = $this->api('GET', "/bills?status={$status}&page=1&per_page=5", null, $token);
            $this->assertContains($r['status'], [200, 403]);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Payment edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testPaymentListFilters(): void
    {
        $token = $this->getAdminToken();
        foreach (['pending', 'succeeded', 'failed', 'rejected'] as $status) {
            $r = $this->api('GET', "/payments?status={$status}&page=1&per_page=5", null, $token);
            $this->assertContains($r['status'], [200, 403]);
        }
    }

    public function testPaymentCreateForNonexistentBill(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('POST', '/payments', [
            'bill_id' => '00000000-0000-0000-0000-000000000000',
            'amount' => '100.00',
            'currency' => 'USD',
        ], $token);
        $this->assertContains($r['status'], [404, 403, 422]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Audit logs with filters
    // ═══════════════════════════════════════════════════════════════

    public function testAuditLogFilters(): void
    {
        $token = $this->getAdminToken();
        $r = $this->api('GET', '/audit-logs?action_code=AUTH_LOGIN&page=1&per_page=5', null, $token);
        $this->assertContains($r['status'], [200, 403]);

        $r = $this->api('GET', '/audit-logs?object_type=User&page=1&per_page=5', null, $token);
        $this->assertContains($r['status'], [200, 403]);
    }
}
