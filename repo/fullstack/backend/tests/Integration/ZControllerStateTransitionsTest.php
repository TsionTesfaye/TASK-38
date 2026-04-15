<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Triggers InvalidStateTransition catch branches in controllers by attempting
 * illegal state transitions via HTTP — e.g. check-in on a completed booking,
 * complete on a canceled booking, void on an already-voided bill.
 */
class ZControllerStateTransitionsTest extends WebTestCase
{
    private ?string $adminToken = null;
    private ?string $tenantToken = null;
    private ?string $itemId = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::ensureKernelShutdown();
    }

    private function api(string $method, string $path, ?array $body = null, ?string $token = null, array $extra = []): array
    {
        $client = static::$booted ? static::getClient() : static::createClient();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token) $server['HTTP_AUTHORIZATION'] = "Bearer {$token}";
        foreach ($extra as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        $client->request($method, "/api/v1{$path}", [], [], $server, $body !== null ? json_encode($body) : null);
        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body' => json_decode($client->getResponse()->getContent(), true) ?? [],
        ];
    }

    private function signPayload(array $payload): string
    {
        ksort($payload);
        $secret = $_ENV['PAYMENT_SHARED_SECRET'] ?? 'local_payment_shared_secret';
        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    private function admin(): string
    {
        if ($this->adminToken) return $this->adminToken;
        $this->api('POST', '/bootstrap', [
            'organization_name' => 'STOrg', 'organization_code' => 'ST',
            'admin_username' => 'st_admin', 'admin_password' => 'password123',
            'admin_display_name' => 'ST',
        ]);
        foreach ([
            ['st_admin', 'password123'],
            ['rbac_admin', 'password123'],
            ['branch_admin', 'password123'],
            ['term_admin', 'password123'],
            ['svc_admin', 'password123'],
            ['xtra_admin', 'password123'],
            ['flows_admin', 'password123'],
            ['http_test_admin', 'secure_pass_123'],
            ['session_cap_admin', 'secure_pass_123'],
            ['uniq_admin', 'secure_pass_123'],
            ['payadmin', 'password123'],
            ['real_http_admin', 'password123'],
            ['all_ctrl_admin', 'password123'],
            ['admin', 'password123'],
            ['e2e_admin', 'e2e_password_123'],
        ] as [$u, $p]) {
            $r = $this->api('POST', '/auth/login', [
                'username' => $u, 'password' => $p,
                'device_label' => 'st', 'client_device_id' => 'st-' . uniqid(),
            ]);
            if ($r['status'] === 200) {
                $this->adminToken = $r['body']['data']['access_token'];
                return $this->adminToken;
            }
        }
        $this->fail('admin login');
    }

    private function setupItemAndTenant(): void
    {
        $admin = $this->admin();
        // Create dedicated item with pricing
        $uid = 'ST-' . bin2hex(random_bytes(4));
        $r = $this->api('POST', '/inventory', [
            'asset_code' => $uid, 'name' => 'ST Item', 'asset_type' => 'studio',
            'location_name' => 'A', 'capacity_mode' => 'discrete_units',
            'total_capacity' => 3, 'timezone' => 'UTC',
        ], $admin);
        $this->assertSame(201, $r['status']);
        $this->itemId = $r['body']['data']['id'];
        $this->api('POST', "/inventory/{$this->itemId}/pricing", [
            'rate_type' => 'daily', 'amount' => '100.00', 'currency' => 'USD',
            'effective_from' => '2026-01-01T00:00:00Z',
        ], $admin);

        // Create tenant
        $tu = 'st_tenant_' . bin2hex(random_bytes(3));
        $cu = $this->api('POST', '/users', [
            'username' => $tu, 'password' => 'tenpass123',
            'display_name' => 'ST Tenant', 'role' => 'tenant',
        ], $admin);
        $this->assertSame(201, $cu['status']);

        $l = $this->api('POST', '/auth/login', [
            'username' => $tu, 'password' => 'tenpass123',
            'device_label' => 'st-t', 'client_device_id' => 'st-t-' . uniqid(),
        ]);
        $this->assertSame(200, $l['status']);
        $this->tenantToken = $l['body']['data']['access_token'];
    }

    private function createConfirmedBooking(string $prefix): string
    {
        $year = 3100 + random_int(1, 99);
        $h = $this->api('POST', '/holds', [
            'inventory_item_id' => $this->itemId, 'held_units' => 1,
            'start_at' => sprintf('%04d-07-15T10:00:00Z', $year),
            'end_at' => sprintf('%04d-07-16T10:00:00Z', $year),
            'request_key' => "{$prefix}-h-" . bin2hex(random_bytes(3)),
        ], $this->tenantToken);
        $this->assertSame(201, $h['status']);
        $c = $this->api('POST', "/holds/{$h['body']['data']['id']}/confirm", [
            'request_key' => "{$prefix}-c-" . bin2hex(random_bytes(3)),
        ], $this->tenantToken);
        $this->assertSame(200, $c['status']);
        return $c['body']['data']['id'];
    }

    // ═══════════════════════════════════════════════════════════════
    // Booking state transitions
    // ═══════════════════════════════════════════════════════════════

    public function testCheckInRejectedAfterComplete(): void
    {
        $this->setupItemAndTenant();
        $admin = $this->admin();
        $bookingId = $this->createConfirmedBooking('ci-cmp');

        // Check in → active
        $ci = $this->api('POST', "/bookings/{$bookingId}/check-in", null, $admin);
        $this->assertSame(200, $ci['status']);
        // Complete → completed
        $cmp = $this->api('POST', "/bookings/{$bookingId}/complete", null, $admin);
        $this->assertSame(200, $cmp['status']);

        // Check in again → 409
        $ci2 = $this->api('POST', "/bookings/{$bookingId}/check-in", null, $admin);
        $this->assertSame(409, $ci2['status']);

        // Complete again → 409
        $cmp2 = $this->api('POST', "/bookings/{$bookingId}/complete", null, $admin);
        $this->assertSame(409, $cmp2['status']);

        // Cancel completed → 409
        $cancel = $this->api('POST', "/bookings/{$bookingId}/cancel", null, $admin);
        $this->assertSame(409, $cancel['status']);

        // No-show completed → 409
        $ns = $this->api('POST', "/bookings/{$bookingId}/no-show", null, $admin);
        $this->assertSame(409, $ns['status']);
    }

    public function testCompleteRejectedBeforeCheckIn(): void
    {
        $this->setupItemAndTenant();
        $admin = $this->admin();
        $bookingId = $this->createConfirmedBooking('cmp-early');

        // Complete on CONFIRMED (not ACTIVE yet) → 409
        $r = $this->api('POST', "/bookings/{$bookingId}/complete", null, $admin);
        $this->assertSame(409, $r['status']);
    }

    public function testCancelThenCheckInRejected(): void
    {
        $this->setupItemAndTenant();
        $admin = $this->admin();
        $bookingId = $this->createConfirmedBooking('cc');

        // Cancel first
        $c = $this->api('POST', "/bookings/{$bookingId}/cancel", null, $admin);
        $this->assertSame(200, $c['status']);

        // Check in canceled → 409
        $ci = $this->api('POST', "/bookings/{$bookingId}/check-in", null, $admin);
        $this->assertSame(409, $ci['status']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bill void state transition
    // ═══════════════════════════════════════════════════════════════

    public function testVoidBillRejectedWhenAlreadyVoided(): void
    {
        $this->setupItemAndTenant();
        $admin = $this->admin();
        $bookingId = $this->createConfirmedBooking('vb');

        // Create a supplemental bill we can void
        $sb = $this->api('POST', '/bills', [
            'booking_id' => $bookingId,
            'amount' => '5.00',
            'reason' => 'late',
        ], $admin);
        $this->assertSame(201, $sb['status']);
        $billId = $sb['body']['data']['id'];

        $v1 = $this->api('POST', "/bills/{$billId}/void", null, $admin);
        $this->assertSame(200, $v1['status']);
        $this->assertSame('voided', $v1['body']['data']['status']);

        // Second void → 409 (InvalidStateTransition)
        $v2 = $this->api('POST', "/bills/{$billId}/void", null, $admin);
        $this->assertSame(409, $v2['status']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Payment callback state transitions
    // ═══════════════════════════════════════════════════════════════

    public function testPaymentCallbackReprocessingIsIdempotent(): void
    {
        $this->setupItemAndTenant();
        $bookingId = $this->createConfirmedBooking('pc');

        $bills = $this->api('GET', '/bills?page=1&per_page=100', null, $this->tenantToken);
        $bill = null;
        foreach ($bills['body']['data']['data'] ?? [] as $b) {
            if (($b['booking_id'] ?? '') === $bookingId) { $bill = $b; break; }
        }
        $this->assertNotNull($bill);

        $p = $this->api('POST', '/payments', [
            'bill_id' => $bill['id'],
            'amount' => $bill['original_amount'],
            'currency' => $bill['currency'],
        ], $this->tenantToken);
        $this->assertSame(201, $p['status']);
        $reqId = $p['body']['data']['request_id'];

        $payload = [
            'request_id' => $reqId,
            'status' => 'succeeded',
            'amount' => $bill['original_amount'],
            'currency' => $bill['currency'],
        ];
        $sig = $this->signPayload($payload);

        $cb1 = $this->api('POST', '/payments/callback', $payload, null, ['X-Payment-Signature' => $sig]);
        $this->assertSame(200, $cb1['status']);

        // Re-deliver same callback → idempotent 409 (or 200 on some implementations)
        $cb2 = $this->api('POST', '/payments/callback', $payload, null, ['X-Payment-Signature' => $sig]);
        $this->assertContains($cb2['status'], [200, 409]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Hold state transitions
    // ═══════════════════════════════════════════════════════════════

    public function testConfirmAlreadyConvertedHoldRejected(): void
    {
        $this->setupItemAndTenant();
        $bookingId = $this->createConfirmedBooking('hld');

        // Try to confirm a hold that was already converted (we need its id)
        $bookings = $this->api('GET', '/bookings', null, $this->tenantToken);
        $booking = null;
        foreach ($bookings['body']['data']['data'] ?? [] as $b) {
            if ($b['id'] === $bookingId) { $booking = $b; break; }
        }
        if (!$booking || empty($booking['source_hold_id'])) {
            $this->markTestSkipped('source_hold_id not exposed');
        }
        $holdId = $booking['source_hold_id'];

        $c = $this->api('POST', "/holds/{$holdId}/confirm", [
            'request_key' => 'reconfirm-' . uniqid(),
        ], $this->tenantToken);
        $this->assertContains($c['status'], [409, 400]);
    }

    public function testReleaseConvertedHoldRejected(): void
    {
        $this->setupItemAndTenant();
        $bookingId = $this->createConfirmedBooking('rel');
        $bookings = $this->api('GET', '/bookings', null, $this->tenantToken);
        $booking = null;
        foreach ($bookings['body']['data']['data'] ?? [] as $b) {
            if ($b['id'] === $bookingId) { $booking = $b; break; }
        }
        if (!$booking || empty($booking['source_hold_id'])) {
            $this->markTestSkipped();
        }
        $holdId = $booking['source_hold_id'];

        $rel = $this->api('POST', "/holds/{$holdId}/release", null, $this->tenantToken);
        $this->assertContains($rel['status'], [400, 409]);
    }
}
