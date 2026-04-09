<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Real HTTP integration tests using WebTestCase.
 * Boots real Symfony kernel, hits real endpoints, uses real DB.
 */
class FullFlowHttpTest extends WebTestCase
{
    private ?string $adminTokenCache = null;
    private ?string $tenantTokenCache = null;
    private ?string $itemIdCache = null;
    private static int $dateOffset = 0;

    /** Generate unique non-overlapping date pair using random month/day. */
    private function uniqueDates(): array
    {
        self::$dateOffset++;
        $month = str_pad((string)((self::$dateOffset % 12) + 1), 2, '0', STR_PAD_LEFT);
        $day = str_pad((string)((self::$dateOffset % 28) + 1), 2, '0', STR_PAD_LEFT);
        return ["2050-{$month}-{$day}T09:00:00Z", "2050-{$month}-{$day}T21:00:00Z"];
    }

    private function client(): KernelBrowser
    {
        // Reuse existing client if the kernel is already booted
        if (static::$booted) {
            return static::getClient();
        }
        return static::createClient();
    }

    private function api(string $method, string $path, ?array $body = null, ?string $token = null, array $extraHeaders = []): array
    {
        $client = $this->client();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token) {
            $server['HTTP_AUTHORIZATION'] = "Bearer $token";
        }
        foreach ($extraHeaders as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $client->request($method, "/api/v1$path", [], [], $server, $body !== null ? json_encode($body) : null);
        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body' => json_decode($client->getResponse()->getContent(), true) ?? [],
        ];
    }

    private function getAdminToken(): string
    {
        if ($this->adminTokenCache) return $this->adminTokenCache;

        $this->api('POST', '/bootstrap', [
            'organization_name' => 'QA Org', 'organization_code' => 'QA',
            'admin_username' => 'admin', 'admin_password' => 'password123',
            'admin_display_name' => 'Admin', 'default_currency' => 'USD',
        ]);

        // Try known credentials (may have been bootstrapped by another test class).
        foreach (['admin', 'http_test_admin', 'session_cap_admin', 'uniq_admin', 'payadmin'] as $user) {
            $r = $this->api('POST', '/auth/login', [
                'username' => $user, 'password' => ($user === 'admin' || $user === 'payadmin') ? 'password123' : 'secure_pass_123',
                'device_label' => 'test', 'client_device_id' => 'test-' . uniqid(),
            ]);
            if ($r['status'] === 200) {
                $this->adminTokenCache = $r['body']['data']['access_token'];
                return $this->adminTokenCache;
            }
        }
        $this->fail('Admin login failed with all known credentials');
        return '';
    }

    private function getTenantToken(): string
    {
        if ($this->tenantTokenCache) return $this->tenantTokenCache;

        $admin = $this->getAdminToken();
        $uid = 'ht_' . substr(uniqid(), 0, 10);
        $this->api('POST', '/users', [
            'username' => $uid, 'password' => 'tenant_pass_123',
            'display_name' => 'HTTP Tenant', 'role' => 'tenant',
        ], $admin);

        $r = $this->api('POST', '/auth/login', [
            'username' => $uid, 'password' => 'tenant_pass_123',
            'device_label' => 'test', 'client_device_id' => 'test-t-' . uniqid(),
        ]);
        $this->assertSame(200, $r['status'], 'Tenant login');
        $this->tenantTokenCache = $r['body']['data']['access_token'];
        return $this->tenantTokenCache;
    }

    private function getItemId(): string
    {
        if ($this->itemIdCache) return $this->itemIdCache;

        $admin = $this->getAdminToken();

        // Try to find existing inventory item with capacity >= 2
        $r = $this->api('GET', '/inventory?page=1&per_page=50', null, $admin);
        if ($r['status'] === 200 && !empty($r['body']['data']['data'])) {
            foreach ($r['body']['data']['data'] as $item) {
                if ($item['total_capacity'] >= 2) {
                    $this->itemIdCache = $item['id'];
                    return $this->itemIdCache;
                }
            }
        }

        $uid = 'HT-' . substr(uniqid(), 0, 8);
        $r = $this->api('POST', '/inventory', [
            'asset_code' => $uid, 'name' => 'HTTP Test', 'asset_type' => 'studio',
            'location_name' => 'A', 'capacity_mode' => 'discrete_units',
            'total_capacity' => 2, 'timezone' => 'UTC',
        ], $admin);
        $this->assertSame(201, $r['status'], 'Create inventory');
        $this->itemIdCache = $r['body']['data']['id'];
        $this->api('POST', '/inventory/' . $this->itemIdCache . '/pricing', [
            'rate_type' => 'daily', 'amount' => '100.00', 'currency' => 'USD',
            'effective_from' => '2026-01-01T00:00:00Z',
        ], $admin);
        return $this->itemIdCache;
    }

    // ═══════════════════════════════════════════════════════════════
    // AUTH
    // ═══════════════════════════════════════════════════════════════

    public function testUnauthenticatedReturns401(): void
    {
        $r = $this->api('GET', '/bookings');
        $this->assertSame(401, $r['status']);
    }

    public function testBadTokenReturns401(): void
    {
        $r = $this->api('GET', '/bookings', null, 'bad.token.here');
        $this->assertSame(401, $r['status']);
    }

    public function testBadCredentialsReturns401(): void
    {
        $r = $this->api('POST', '/auth/login', [
            'username' => 'nobody', 'password' => 'wrong',
            'device_label' => 't', 'client_device_id' => 't',
        ]);
        $this->assertSame(401, $r['status']);
    }

    public function testHealthIsPublic(): void
    {
        $r = $this->api('GET', '/health');
        $this->assertSame(200, $r['status']);
        $this->assertSame('ok', $r['body']['data']['status']);
    }

    public function testLoginReturnsTokens(): void
    {
        $token = $this->getAdminToken();
        $this->assertNotEmpty($token);
    }

    // ═══════════════════════════════════════════════════════════════
    // RBAC VIA HTTP
    // ═══════════════════════════════════════════════════════════════

    public function testTenantCannotAccessAuditLogs(): void
    {
        $t = $this->getTenantToken();
        $this->assertNotEmpty($t, 'Setup prerequisite failed');
        $r = $this->api('GET', '/audit-logs', null, $t);
        $this->assertSame(403, $r['status']);
    }

    public function testTenantCannotUpdateSettings(): void
    {
        $t = $this->getTenantToken();
        $this->assertNotEmpty($t, 'Setup prerequisite failed');
        $r = $this->api('PUT', '/settings', ['timezone' => 'UTC'], $t);
        $this->assertSame(403, $r['status']);
    }

    public function testTenantCannotCreateInventory(): void
    {
        $t = $this->getTenantToken();
        $this->assertNotEmpty($t, 'Setup prerequisite failed');
        $r = $this->api('POST', '/inventory', [
            'asset_code' => 'X', 'name' => 'X', 'asset_type' => 'x',
            'location_name' => 'x', 'capacity_mode' => 'discrete_units',
            'total_capacity' => 1, 'timezone' => 'UTC',
        ], $t);
        $this->assertSame(403, $r['status']);
    }

    // ═══════════════════════════════════════════════════════════════
    // TENANT ISOLATION
    // ═══════════════════════════════════════════════════════════════

    public function testTenantCannotAccessOtherTenantsBooking(): void
    {
        $admin = $this->getAdminToken();
        $t1 = $this->getTenantToken();
        $this->assertNotEmpty($t1, 'Setup prerequisite failed');

        $itemId = $this->getItemId();
        $this->assertNotEmpty($itemId, 'Setup prerequisite failed');

        // T1 creates hold + booking
        $r = $this->api('POST', '/holds', [
            'inventory_item_id' => $itemId, 'held_units' => 1,
            'start_at' => sprintf('%d-06-15T09:00:00Z', $_y1 = 2200 + random_int(1, 7799)), 'end_at' => sprintf('%d-06-16T09:00:00Z', $_y1),
            'request_key' => uniqid(),
        ], $t1);
        $this->assertSame(201, $r['status'], 'Prerequisite request failed');
        $holdId = $r['body']['data']['id'];

        $r = $this->api('POST', "/holds/$holdId/confirm", ['request_key' => uniqid()], $t1);
        $this->assertSame(200, $r['status'], 'Prerequisite request failed');
        $bookingId = $r['body']['data']['id'];

        // Create a second tenant
        $uid2 = 'iso_t2_' . substr(uniqid(), 0, 6);
        $this->api('POST', '/users', [
            'username' => $uid2, 'password' => 'tenant_pass_123',
            'display_name' => 'Iso T2', 'role' => 'tenant',
        ], $admin);
        $r2 = $this->api('POST', '/auth/login', [
            'username' => $uid2, 'password' => 'tenant_pass_123',
            'device_label' => 't', 'client_device_id' => 't2-' . uniqid(),
        ]);
        $this->assertSame(200, $r2['status'], 'Prerequisite request failed');
        $t2Token = $r2['body']['data']['access_token'];

        // T2 tries to access T1's booking → 403
        $r = $this->api('GET', "/bookings/$bookingId", null, $t2Token);
        $this->assertSame(403, $r['status'], 'Cross-tenant must be 403');
    }

    // ═══════════════════════════════════════════════════════════════
    // BOOKING FLOW END-TO-END
    // ═══════════════════════════════════════════════════════════════

    public function testFullBookingLifecycle(): void
    {
        $t = $this->getTenantToken();
        $itemId = $this->getItemId();
        $this->assertNotEmpty($t, 'Setup prerequisite 1 failed');
        $this->assertNotEmpty($itemId, 'Setup prerequisite 2 failed');

        // Create hold
        $holdKey = uniqid();
        $r = $this->api('POST', '/holds', [
            'inventory_item_id' => $itemId, 'held_units' => 1,
            'start_at' => sprintf('%d-06-15T09:00:00Z', $_y2 = 2300 + random_int(1, 7699)), 'end_at' => sprintf('%d-06-16T09:00:00Z', $_y2),
            'request_key' => $holdKey,
        ], $t);
        $this->assertSame(201, $r['status'], 'Create hold');
        $this->assertSame('active', $r['body']['data']['status']);
        $holdId = $r['body']['data']['id'];

        // Confirm → booking
        $r = $this->api('POST', "/holds/$holdId/confirm", ['request_key' => uniqid()], $t);
        $this->assertSame(200, $r['status'], 'Confirm hold');
        $this->assertSame('confirmed', $r['body']['data']['status']);
        $bookingId = $r['body']['data']['id'];

        // Get booking — verify
        $r = $this->api('GET', "/bookings/$bookingId", null, $t);
        $this->assertSame('confirmed', $r['body']['data']['status']);

        // Cancel
        $r = $this->api('POST', "/bookings/$bookingId/cancel", null, $t);
        $this->assertSame(200, $r['status'], 'Cancel');
        $this->assertSame('canceled', $r['body']['data']['status']);

        // Double cancel fails
        $r = $this->api('POST', "/bookings/$bookingId/cancel", null, $t);
        $this->assertNotSame(200, $r['status'], 'Double cancel must fail');
    }

    // ═══════════════════════════════════════════════════════════════
    // IDEMPOTENCY
    // ═══════════════════════════════════════════════════════════════

    public function testDuplicateHoldKeyReturns409(): void
    {
        $t = $this->getTenantToken();
        $itemId = $this->getItemId();
        $this->assertNotEmpty($t, 'Setup prerequisite 1 failed');
        $this->assertNotEmpty($itemId, 'Setup prerequisite 2 failed');

        $key = uniqid();
        $payload = [
            'inventory_item_id' => $itemId, 'held_units' => 1,
            'start_at' => sprintf('%d-06-15T09:00:00Z', $_y3 = 2400 + random_int(1, 7599)), 'end_at' => sprintf('%d-06-16T09:00:00Z', $_y3),
            'request_key' => $key,
        ];

        $r1 = $this->api('POST', '/holds', $payload, $t);
        $this->assertSame(201, $r1['status']);

        $r2 = $this->api('POST', '/holds', $payload, $t);
        $this->assertSame(409, $r2['status'], 'Duplicate key → 409');
    }

    // ═══════════════════════════════════════════════════════════════
    // CONCURRENCY — CAPACITY EXHAUSTION
    // ═══════════════════════════════════════════════════════════════

    public function testCapacityExhaustedBlocks(): void
    {
        $t = $this->getTenantToken();
        $this->assertNotEmpty($t, 'Setup prerequisite failed');

        // Check current availability on the shared item to pick a free slot
        $itemId = $this->getItemId(); // capacity=2
        $this->assertNotEmpty($itemId, 'Setup prerequisite failed');

        // Use microsecond-unique date range — append random offset to avoid DB collision
        $rand = random_int(1, 9999);
        $year = 2100 + ($rand % 7899);
        $start = sprintf('%04d-06-15T09:00:00Z', $year);
        $end = sprintf('%04d-06-16T09:00:00Z', $year);

        // Hold 1: 1 unit
        $r1 = $this->api('POST', '/holds', [
            'inventory_item_id' => $itemId, 'held_units' => 1,
            'start_at' => $start, 'end_at' => $end,
            'request_key' => uniqid('c1_'),
        ], $t);
        $this->assertSame(201, $r1['status'], 'Hold 1');

        // Hold 2: 1 unit (fills remaining capacity)
        $r2 = $this->api('POST', '/holds', [
            'inventory_item_id' => $itemId, 'held_units' => 1,
            'start_at' => $start, 'end_at' => $end,
            'request_key' => uniqid('c2_'),
        ], $t);
        $this->assertSame(201, $r2['status'], 'Hold 2');

        // Hold 3: capacity exhausted
        $r3 = $this->api('POST', '/holds', [
            'inventory_item_id' => $itemId, 'held_units' => 1,
            'start_at' => $start, 'end_at' => $end,
            'request_key' => uniqid('c3_'),
        ], $t);
        $this->assertSame(409, $r3['status'], 'Capacity exhausted → 409');
    }

    // ═══════════════════════════════════════════════════════════════
    // PAYMENT CALLBACK — FULL INTEGRATION SUITE
    // ═══════════════════════════════════════════════════════════════

    /**
     * Compute HMAC-SHA256 signature matching PaymentSignatureVerifier logic.
     */
    private function signPayload(array $payload): string
    {
        $secret = $_ENV['PAYMENT_SHARED_SECRET'] ?? $_SERVER['PAYMENT_SHARED_SECRET'] ?? 'local_payment_shared_secret';
        ksort($payload);
        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    /**
     * Create a confirmed booking with a bill, then initiate a pending payment.
     * Returns [request_id, bill_id, amount, currency, payment_id].
     */
    private function createPendingPayment(): array
    {
        $tenant = $this->getTenantToken();
        $itemId = $this->getItemId();

        $year = 2600 + random_int(1, 7000);
        $start = sprintf('%04d-03-15T09:00:00Z', $year);
        $end = sprintf('%04d-03-16T09:00:00Z', $year);

        // Hold
        $r = $this->api('POST', '/holds', [
            'inventory_item_id' => $itemId, 'held_units' => 1,
            'start_at' => $start, 'end_at' => $end,
            'request_key' => 'pcb-h-' . uniqid(),
        ], $tenant);
        $this->assertSame(201, $r['status'], 'Setup: create hold failed: ' . json_encode($r['body']));
        $holdId = $r['body']['data']['id'];

        // Confirm → creates booking + bill
        $r = $this->api('POST', "/holds/$holdId/confirm", ['request_key' => 'pcb-c-' . uniqid()], $tenant);
        $this->assertSame(200, $r['status'], 'Setup: confirm hold failed: ' . json_encode($r['body']));
        $bookingId = $r['body']['data']['id'];

        // Find the bill for this booking
        $r = $this->api('GET', '/bills?page=1&per_page=100', null, $tenant);
        $this->assertSame(200, $r['status'], 'Setup: list bills failed');
        $bills = $r['body']['data']['data'] ?? $r['body']['data'] ?? [];
        $bill = null;
        foreach ($bills as $b) {
            if (($b['booking_id'] ?? '') === $bookingId) { $bill = $b; break; }
        }
        $this->assertNotNull($bill, 'Setup: bill not found for booking');

        // Initiate payment
        $r = $this->api('POST', '/payments', [
            'bill_id' => $bill['id'], 'amount' => $bill['original_amount'], 'currency' => $bill['currency'],
        ], $tenant);
        $this->assertSame(201, $r['status'], 'Setup: initiate payment failed: ' . json_encode($r['body']));

        return [
            'request_id' => $r['body']['data']['request_id'],
            'bill_id' => $bill['id'],
            'amount' => $bill['original_amount'],
            'currency' => $bill['currency'],
            'payment_id' => $r['body']['data']['id'],
        ];
    }

    /**
     * Get the DBAL connection for direct DB assertions.
     */
    private function db(): \Doctrine\DBAL\Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    // ─── 1. INVALID SIGNATURE → 401, NO DB MUTATION ─────────────

    public function testPaymentCallbackInvalidSignatureReturns401NoDbMutation(): void
    {
        $payment = $this->createPendingPayment();

        // Snapshot: payment must still be pending
        $before = $this->db()->fetchAssociative('SELECT status FROM payments WHERE id = ?', [$payment['payment_id']]);
        $this->assertSame('pending', $before['status']);

        $payload = [
            'request_id' => $payment['request_id'],
            'status' => 'succeeded',
            'amount' => $payment['amount'],
            'currency' => $payment['currency'],
        ];

        $r = $this->api('POST', '/payments/callback', $payload, null, [
            'X-Payment-Signature' => 'invalid_signature_value',
        ]);

        // HTTP: must be 401
        $this->assertSame(401, $r['status']);
        $this->assertStringContainsString('signature', strtolower($r['body']['message'] ?? ''));

        // DB: payment must still be pending (no mutation)
        $after = $this->db()->fetchAssociative('SELECT status FROM payments WHERE id = ?', [$payment['payment_id']]);
        $this->assertSame('pending', $after['status']);

        // DB: no ledger entry for this payment
        $ledgerCount = (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE payment_id = ?', [$payment['payment_id']]
        );
        $this->assertSame(0, $ledgerCount, 'No ledger entry must exist after invalid signature');
    }

    // ─── 2. AMOUNT MISMATCH → 422, NO DB MUTATION ───────────────

    public function testPaymentCallbackAmountMismatchReturns422NoDbMutation(): void
    {
        $payment = $this->createPendingPayment();

        $payload = [
            'request_id' => $payment['request_id'],
            'status' => 'succeeded',
            'amount' => '999999.99', // WRONG amount
            'currency' => $payment['currency'],
        ];

        $r = $this->api('POST', '/payments/callback', $payload, null, [
            'X-Payment-Signature' => $this->signPayload($payload),
        ]);

        // HTTP: must be 422
        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('amount', strtolower($r['body']['message'] ?? ''));

        // DB: payment still pending
        $row = $this->db()->fetchAssociative('SELECT status FROM payments WHERE id = ?', [$payment['payment_id']]);
        $this->assertSame('pending', $row['status']);

        // DB: bill unchanged
        $bill = $this->db()->fetchAssociative('SELECT status, outstanding_amount FROM bills WHERE id = ?', [$payment['bill_id']]);
        $this->assertSame('open', $bill['status']);

        // DB: no ledger entry
        $ledgerCount = (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE payment_id = ?', [$payment['payment_id']]
        );
        $this->assertSame(0, $ledgerCount);
    }

    // ─── 3. CURRENCY MISMATCH → 422, NO DB MUTATION ─────────────

    public function testPaymentCallbackCurrencyMismatchReturns422NoDbMutation(): void
    {
        $payment = $this->createPendingPayment();

        $payload = [
            'request_id' => $payment['request_id'],
            'status' => 'succeeded',
            'amount' => $payment['amount'],
            'currency' => 'EUR', // WRONG currency
        ];

        $r = $this->api('POST', '/payments/callback', $payload, null, [
            'X-Payment-Signature' => $this->signPayload($payload),
        ]);

        // HTTP: must be 422
        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('currency', strtolower($r['body']['message'] ?? ''));

        // DB: payment still pending
        $row = $this->db()->fetchAssociative('SELECT status FROM payments WHERE id = ?', [$payment['payment_id']]);
        $this->assertSame('pending', $row['status']);

        // DB: no ledger entry
        $ledgerCount = (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE payment_id = ?', [$payment['payment_id']]
        );
        $this->assertSame(0, $ledgerCount);
    }

    // ─── 4. REPLAY ATTACK → IDEMPOTENT, NO DUPLICATE SIDE EFFECTS ─

    public function testPaymentCallbackReplayIsIdempotentNoDbDuplicates(): void
    {
        $payment = $this->createPendingPayment();

        $payload = [
            'request_id' => $payment['request_id'],
            'status' => 'succeeded',
            'amount' => $payment['amount'],
            'currency' => $payment['currency'],
        ];
        $sig = $this->signPayload($payload);

        // ── First callback: processes normally ──
        $r1 = $this->api('POST', '/payments/callback', $payload, null, ['X-Payment-Signature' => $sig]);
        $this->assertSame(200, $r1['status'], 'First callback must succeed');
        $this->assertSame('succeeded', $r1['body']['data']['status']);

        // DB snapshot after first call
        $ledgerCountAfterFirst = (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE payment_id = ?', [$payment['payment_id']]
        );
        $this->assertSame(1, $ledgerCountAfterFirst, 'Exactly 1 ledger entry after first callback');

        // ── Second callback (replay): must be idempotent ──
        $r2 = $this->api('POST', '/payments/callback', $payload, null, ['X-Payment-Signature' => $sig]);
        $this->assertSame(200, $r2['status'], 'Replay must return 200');
        $this->assertSame('succeeded', $r2['body']['data']['status']);
        $this->assertSame($r1['body']['data']['id'], $r2['body']['data']['id'], 'Must return same payment');

        // DB: still exactly 1 ledger entry (no duplicate)
        $ledgerCountAfterReplay = (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries WHERE payment_id = ?', [$payment['payment_id']]
        );
        $this->assertSame(1, $ledgerCountAfterReplay, 'Must NOT create duplicate ledger entry on replay');

        // DB: payment still succeeded (not re-processed)
        $row = $this->db()->fetchAssociative('SELECT status FROM payments WHERE id = ?', [$payment['payment_id']]);
        $this->assertSame('succeeded', $row['status']);
    }

    // ─── 5. VALID CALLBACK → SUCCESS + FULL DB SIDE EFFECTS ─────

    public function testPaymentCallbackSuccessUpdatesAllDbState(): void
    {
        $payment = $this->createPendingPayment();

        $payload = [
            'request_id' => $payment['request_id'],
            'status' => 'succeeded',
            'amount' => $payment['amount'],
            'currency' => $payment['currency'],
        ];

        $r = $this->api('POST', '/payments/callback', $payload, null, [
            'X-Payment-Signature' => $this->signPayload($payload),
        ]);

        // HTTP: 200 with payment data
        $this->assertSame(200, $r['status']);
        $this->assertSame('succeeded', $r['body']['data']['status']);
        $this->assertTrue($r['body']['data']['signature_verified']);

        // DB: payment marked succeeded
        $paymentRow = $this->db()->fetchAssociative('SELECT * FROM payments WHERE id = ?', [$payment['payment_id']]);
        $this->assertSame('succeeded', $paymentRow['status']);
        $this->assertNotNull($paymentRow['processed_at'], 'processed_at must be set');

        // DB: bill status updated (paid, since full amount was paid)
        $billRow = $this->db()->fetchAssociative('SELECT * FROM bills WHERE id = ?', [$payment['bill_id']]);
        $this->assertSame('paid', $billRow['status']);
        $this->assertSame('0.00', $billRow['outstanding_amount']);

        // DB: ledger entry created with correct type and amount
        $ledger = $this->db()->fetchAssociative(
            'SELECT * FROM ledger_entries WHERE payment_id = ? AND entry_type = ?',
            [$payment['payment_id'], 'payment_received']
        );
        $this->assertNotFalse($ledger, 'PAYMENT_RECEIVED ledger entry must exist');
        $this->assertSame($payment['amount'], $ledger['amount']);
        $this->assertSame($payment['currency'], $ledger['currency']);
        $this->assertSame($payment['bill_id'], $ledger['bill_id']);
    }

    // ═══════════════════════════════════════════════════════════════
    // VALIDATION
    // ═══════════════════════════════════════════════════════════════

    public function testReversedDatesRejected(): void
    {
        $t = $this->getTenantToken();
        $this->assertNotEmpty($t, 'Setup prerequisite failed');
        $r = $this->api('POST', '/holds', [
            'inventory_item_id' => 'any', 'held_units' => 1,
            'start_at' => '2033-05-02T09:00:00Z', 'end_at' => '2033-05-01T09:00:00Z',
            'request_key' => uniqid(),
        ], $t);
        $this->assertSame(422, $r['status']);
    }

    public function testMissingFieldsRejected(): void
    {
        $t = $this->getTenantToken();
        $this->assertNotEmpty($t, 'Setup prerequisite failed');
        $r = $this->api('POST', '/holds', [
            'inventory_item_id' => 'x', 'held_units' => 1, 'request_key' => 'k',
        ], $t);
        $this->assertSame(422, $r['status']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ERROR LEAKAGE
    // ═══════════════════════════════════════════════════════════════

    public function testErrorDoesNotLeakInternals(): void
    {
        $r = $this->api('GET', '/bookings', null, 'bad.jwt');
        $body = json_encode($r['body']);
        $this->assertStringNotContainsString('.php', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString('vendor/', $body);
    }
}
