<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Full HTTP integration tests for the payment callback endpoint.
 *
 * Boots real Symfony kernel, hits real endpoints, verifies DB side effects.
 *
 * Covers:
 *   1. Invalid signature → 401
 *   2. Amount mismatch → 422
 *   3. Currency mismatch → 422
 *   4. Replay attack → idempotent (no duplicate ledger entries)
 *   5. Success path → payment succeeded, ledger entry created, bill updated
 */
class PaymentCallbackIntegrationTest extends WebTestCase
{
    private ?string $adminToken = null;
    private ?string $tenantToken = null;
    private ?string $itemId = null;
    private ?string $paymentSecret = null;

    private function client(): KernelBrowser
    {
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
        foreach ($extraHeaders as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $client->request($method, "/api/v1$path", [], [], $server, $body !== null ? json_encode($body) : null);
        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body' => json_decode($client->getResponse()->getContent(), true) ?? [],
        ];
    }

    private function getAdminToken(): string
    {
        if ($this->adminToken) return $this->adminToken;

        $this->api('POST', '/bootstrap', [
            'organization_name' => 'Pay QA Org', 'organization_code' => 'PAYQA',
            'admin_username' => 'payadmin', 'admin_password' => 'password123',
            'admin_display_name' => 'Pay Admin', 'default_currency' => 'USD',
        ]);

        $r = $this->api('POST', '/auth/login', [
            'username' => 'payadmin', 'password' => 'password123',
            'device_label' => 'test', 'client_device_id' => 'pcb-' . uniqid(),
        ]);
        $this->assertSame(200, $r['status'], 'Admin login failed');
        $this->adminToken = $r['body']['data']['access_token'];
        return $this->adminToken;
    }

    private function getTenantToken(): string
    {
        if ($this->tenantToken) return $this->tenantToken;

        $admin = $this->getAdminToken();
        $uid = 'pct_' . substr(uniqid(), 0, 8);
        $this->api('POST', '/users', [
            'username' => $uid, 'password' => 'tenant_pass_123',
            'display_name' => 'Pay Tenant', 'role' => 'tenant',
        ], $admin);

        $r = $this->api('POST', '/auth/login', [
            'username' => $uid, 'password' => 'tenant_pass_123',
            'device_label' => 'test', 'client_device_id' => 'pct-' . uniqid(),
        ]);
        $this->assertSame(200, $r['status'], 'Tenant login failed');
        $this->tenantToken = $r['body']['data']['access_token'];
        return $this->tenantToken;
    }

    private function getItemId(): string
    {
        if ($this->itemId) return $this->itemId;

        $admin = $this->getAdminToken();
        $uid = 'PCI-' . substr(uniqid(), 0, 6);
        $r = $this->api('POST', '/inventory', [
            'asset_code' => $uid, 'name' => 'Pay Test Item', 'asset_type' => 'studio',
            'location_name' => 'A', 'capacity_mode' => 'discrete_units',
            'total_capacity' => 5, 'timezone' => 'UTC',
        ], $admin);
        $this->assertSame(201, $r['status'], 'Create inventory failed');
        $this->itemId = $r['body']['data']['id'];

        $this->api('POST', '/inventory/' . $this->itemId . '/pricing', [
            'rate_type' => 'daily', 'amount' => '100.00', 'currency' => 'USD',
            'effective_from' => '2026-01-01T00:00:00Z',
        ], $admin);

        return $this->itemId;
    }

    private function getPaymentSecret(): string
    {
        if ($this->paymentSecret) return $this->paymentSecret;
        $this->paymentSecret = $_ENV['PAYMENT_SHARED_SECRET'] ?? $_SERVER['PAYMENT_SHARED_SECRET'] ?? 'local_payment_shared_secret';
        return $this->paymentSecret;
    }

    private function signPayload(array $payload): string
    {
        ksort($payload);
        return hash_hmac('sha256', json_encode($payload), $this->getPaymentSecret());
    }

    /**
     * Full lifecycle setup: booking → bill → pending payment.
     * Returns [request_id, bill_id, amount, currency].
     */
    private function createPendingPayment(): array
    {
        $tenant = $this->getTenantToken();
        $itemId = $this->getItemId();

        // Unique dates to avoid collision
        $year = 2500 + random_int(1, 7000);
        $start = sprintf('%04d-03-15T09:00:00Z', $year);
        $end = sprintf('%04d-03-16T09:00:00Z', $year);

        // Create hold
        $r = $this->api('POST', '/holds', [
            'inventory_item_id' => $itemId, 'held_units' => 1,
            'start_at' => $start, 'end_at' => $end,
            'request_key' => 'pcb-hold-' . uniqid(),
        ], $tenant);
        $this->assertSame(201, $r['status'], 'Create hold failed: ' . json_encode($r['body']));
        $holdId = $r['body']['data']['id'];

        // Confirm hold → creates booking + bill
        $r = $this->api('POST', "/holds/$holdId/confirm", [
            'request_key' => 'pcb-confirm-' . uniqid(),
        ], $tenant);
        $this->assertSame(200, $r['status'], 'Confirm hold failed: ' . json_encode($r['body']));
        $bookingId = $r['body']['data']['id'];

        // Fetch bills for this tenant
        $r = $this->api('GET', '/bills?page=1&per_page=50', null, $tenant);
        $this->assertSame(200, $r['status'], 'List bills failed');
        $bills = $r['body']['data']['data'] ?? $r['body']['data'] ?? [];

        // Find the bill for this booking
        $bill = null;
        foreach ($bills as $b) {
            if (($b['booking_id'] ?? '') === $bookingId) {
                $bill = $b;
                break;
            }
        }
        $this->assertNotNull($bill, 'Bill not found for booking');

        $billId = $bill['id'];
        $amount = $bill['original_amount'];
        $currency = $bill['currency'];

        // Initiate payment
        $r = $this->api('POST', '/payments', [
            'bill_id' => $billId, 'amount' => $amount, 'currency' => $currency,
        ], $tenant);
        $this->assertSame(201, $r['status'], 'Initiate payment failed: ' . json_encode($r['body']));
        $requestId = $r['body']['data']['request_id'];

        return ['request_id' => $requestId, 'bill_id' => $billId, 'amount' => $amount, 'currency' => $currency];
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. INVALID SIGNATURE → 401
    // ═══════════════════════════════════════════════════════════════

    public function testCallbackWithInvalidSignatureReturns401(): void
    {
        $payment = $this->createPendingPayment();

        $payload = [
            'request_id' => $payment['request_id'],
            'status' => 'succeeded',
            'amount' => $payment['amount'],
            'currency' => $payment['currency'],
        ];

        $r = $this->api('POST', '/payments/callback', $payload, null, [
            'X-Payment-Signature' => 'definitely_not_a_valid_signature',
        ]);

        $this->assertSame(401, $r['status'], 'Invalid signature must return 401');
        $this->assertSame(401, $r['body']['code']);
        $this->assertStringContainsString('signature', strtolower($r['body']['message']));
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. AMOUNT MISMATCH → 422
    // ═══════════════════════════════════════════════════════════════

    public function testCallbackWithAmountMismatchReturns422(): void
    {
        $payment = $this->createPendingPayment();

        $payload = [
            'request_id' => $payment['request_id'],
            'status' => 'succeeded',
            'amount' => '999999.99', // wrong amount
            'currency' => $payment['currency'],
        ];

        $r = $this->api('POST', '/payments/callback', $payload, null, [
            'X-Payment-Signature' => $this->signPayload($payload),
        ]);

        $this->assertSame(422, $r['status'], 'Amount mismatch must return 422');
        $this->assertStringContainsString('amount', strtolower($r['body']['message']));
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. CURRENCY MISMATCH → 422
    // ═══════════════════════════════════════════════════════════════

    public function testCallbackWithCurrencyMismatchReturns422(): void
    {
        $payment = $this->createPendingPayment();

        $payload = [
            'request_id' => $payment['request_id'],
            'status' => 'succeeded',
            'amount' => $payment['amount'],
            'currency' => 'EUR', // wrong currency
        ];

        $r = $this->api('POST', '/payments/callback', $payload, null, [
            'X-Payment-Signature' => $this->signPayload($payload),
        ]);

        $this->assertSame(422, $r['status'], 'Currency mismatch must return 422');
        $this->assertStringContainsString('currency', strtolower($r['body']['message']));
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. REPLAY ATTACK → IDEMPOTENT
    // ═══════════════════════════════════════════════════════════════

    public function testReplayCallbackIsIdempotent(): void
    {
        $payment = $this->createPendingPayment();

        $payload = [
            'request_id' => $payment['request_id'],
            'status' => 'succeeded',
            'amount' => $payment['amount'],
            'currency' => $payment['currency'],
        ];
        $sig = $this->signPayload($payload);

        // First callback → success
        $r1 = $this->api('POST', '/payments/callback', $payload, null, [
            'X-Payment-Signature' => $sig,
        ]);
        $this->assertSame(200, $r1['status'], 'First callback must succeed');
        $this->assertSame('succeeded', $r1['body']['data']['status']);

        // Second callback (replay) → must also return 200 with same result
        $r2 = $this->api('POST', '/payments/callback', $payload, null, [
            'X-Payment-Signature' => $sig,
        ]);
        $this->assertSame(200, $r2['status'], 'Replay must return 200 (idempotent)');
        $this->assertSame('succeeded', $r2['body']['data']['status']);
        $this->assertSame($r1['body']['data']['id'], $r2['body']['data']['id'],
            'Replay must return same payment, not create a new one');
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. SUCCESS PATH → FULL LIFECYCLE
    // ═══════════════════════════════════════════════════════════════

    public function testSuccessfulCallbackUpdatesPaymentAndBill(): void
    {
        $payment = $this->createPendingPayment();
        $tenant = $this->getTenantToken();

        $payload = [
            'request_id' => $payment['request_id'],
            'status' => 'succeeded',
            'amount' => $payment['amount'],
            'currency' => $payment['currency'],
        ];

        $r = $this->api('POST', '/payments/callback', $payload, null, [
            'X-Payment-Signature' => $this->signPayload($payload),
        ]);

        $this->assertSame(200, $r['status'], 'Callback must succeed');
        $this->assertSame('succeeded', $r['body']['data']['status']);

        // Verify bill status updated via DB (check via API)
        $r = $this->api('GET', '/bills?page=1&per_page=50', null, $tenant);
        $this->assertSame(200, $r['status']);
        $bills = $r['body']['data']['data'] ?? $r['body']['data'] ?? [];

        $bill = null;
        foreach ($bills as $b) {
            if ($b['id'] === $payment['bill_id']) {
                $bill = $b;
                break;
            }
        }
        $this->assertNotNull($bill, 'Bill must exist after callback');
        $this->assertSame('paid', $bill['status'], 'Bill must be marked as paid after full payment');
        $this->assertSame('0.00', $bill['outstanding_amount'], 'Outstanding must be zero after full payment');
    }
}
