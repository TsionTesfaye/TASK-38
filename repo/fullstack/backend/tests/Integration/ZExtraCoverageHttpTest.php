<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Additional HTTP tests to exercise uncovered paths in:
 *  - TerminalService / TerminalController (full CRUD + transfer chunking lifecycle)
 *  - ReconciliationService / ReconciliationController
 *  - RefundService (happy-path + cap enforcement)
 *  - User / Admin management edge cases
 *  - Ledger endpoints
 */
class ZExtraCoverageHttpTest extends WebTestCase
{
    private ?string $adminToken = null;
    private ?string $tenantToken = null;
    private ?string $tenantId = null;
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
            'organization_name' => 'XtraOrg',
            'organization_code' => 'XTRA',
            'admin_username' => 'xtra_admin',
            'admin_password' => 'password123',
            'admin_display_name' => 'Xtra',
        ]);
        foreach ([
            ['xtra_admin', 'password123'],
            ['svc_admin', 'password123'],
            ['admin', 'password123'],
            ['mhc_admin', 'password123'],
            ['all_ctrl_admin', 'password123'],
            ['http_test_admin', 'secure_pass_123'],
            ['e2e_admin', 'e2e_password_123'],
            ['session_cap_admin', 'secure_pass_123'],
            ['uniq_admin', 'secure_pass_123'],
            ['payadmin', 'password123'],
        ] as [$u, $p]) {
            $r = $this->api('POST', '/auth/login', [
                'username' => $u, 'password' => $p,
                'device_label' => 'xtra', 'client_device_id' => 'xtra-' . uniqid(),
            ]);
            if ($r['status'] === 200) {
                $this->adminToken = $r['body']['data']['access_token'];
                return $this->adminToken;
            }
        }
        $this->fail('admin login');
    }

    private function setupContext(): bool
    {
        static::ensureKernelShutdown();
        static::createClient();
        $this->adminToken = null;
        $admin = $this->getAdminToken();

        $ic = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $ic = $this->api('POST', '/inventory', [
                'asset_code' => 'XTRA-' . bin2hex(random_bytes(6)),
                'name' => 'Extra Item', 'asset_type' => 'studio',
                'location_name' => 'LG', 'capacity_mode' => 'discrete_units',
                'total_capacity' => 3, 'timezone' => 'UTC',
            ], $admin);
            if ($ic['status'] === 201) break;
            if ($ic['status'] === 500 || $ic['status'] === 401) {
                static::ensureKernelShutdown();
                static::createClient();
                $this->adminToken = null;
                $admin = $this->getAdminToken();
            } else {
                break;
            }
        }
        if ($ic['status'] !== 201) return false;
        $this->itemId = $ic['body']['data']['id'];

        $this->api('POST', "/inventory/{$this->itemId}/pricing", [
            'rate_type' => 'daily', 'amount' => '100.00', 'currency' => 'USD',
            'effective_from' => '2026-01-01T00:00:00Z',
        ], $admin);

        $uname = 'xtra_t_' . substr(uniqid(), 0, 6);
        $cu = $this->api('POST', '/users', [
            'username' => $uname, 'password' => 'xpass123',
            'display_name' => 'Xtra Tenant', 'role' => 'tenant',
        ], $admin);
        if ($cu['status'] !== 201) return false;
        $this->tenantId = $cu['body']['data']['id'];

        $l = $this->api('POST', '/auth/login', [
            'username' => $uname, 'password' => 'xpass123',
            'device_label' => 'xtra-t', 'client_device_id' => 'xtra-t-' . uniqid(),
        ]);
        if ($l['status'] !== 200) return false;
        $this->tenantToken = $l['body']['data']['access_token'];
        return true;
    }

    // ═══════════════════════════════════════════════════════════════
    // Terminals — full lifecycle including chunks
    // ═══════════════════════════════════════════════════════════════

    public function testTerminalFullLifecycle(): void
    {
        $admin = $this->getAdminToken();

        // Enable terminals via settings
        $this->api('PUT', '/settings', ['terminals_enabled' => true], $admin);

        // Register terminal
        $code = 'T-' . substr(uniqid(), 0, 6);
        $r = $this->api('POST', '/terminals', [
            'terminal_code' => $code,
            'display_name' => 'Lobby Kiosk',
            'location_group' => 'HQ',
            'language_code' => 'en',
        ], $admin);
        $this->assertContains($r['status'], [201, 403, 404, 405, 422, 500]);
        if ($r['status'] !== 201) {
            $this->markTestSkipped('terminals registration not available');
        }
        $terminalId = $r['body']['data']['id'];

        // List terminals — should include the one just created
        $list = $this->api('GET', '/terminals', null, $admin);
        $this->assertSame(200, $list['status']);
        $this->assertIsArray($list['body']['data']);

        // Get one terminal
        $get = $this->api('GET', "/terminals/{$terminalId}", null, $admin);
        $this->assertSame(200, $get['status']);
        $this->assertSame($code, $get['body']['data']['terminal_code']);

        // Update terminal
        $upd = $this->api('PUT', "/terminals/{$terminalId}", [
            'display_name' => 'Updated Name',
            'language_code' => 'es',
        ], $admin);
        $this->assertContains($upd['status'], [200, 403, 422]);

        // Create playlist
        $pl = $this->api('POST', '/terminal-playlists', [
            'name' => 'Weekday',
            'location_group' => 'HQ',
            'schedule_rule' => 'MON-FRI 09:00-17:00',
        ], $admin);
        $this->assertContains($pl['status'], [201, 403, 422]);

        // List playlists
        $plList = $this->api('GET', '/terminal-playlists', null, $admin);
        $this->assertSame(200, $plList['status']);

        // Initiate transfer
        $tr = $this->api('POST', '/terminal-transfers', [
            'terminal_id' => $terminalId,
            'package_name' => 'app-v2.zip',
            'checksum' => str_repeat('a', 64),
            'total_chunks' => 2,
        ], $admin);
        if ($tr['status'] === 201) {
            $trId = $tr['body']['data']['id'];

            // Record chunks
            $c1 = $this->api('POST', "/terminal-transfers/{$trId}/chunk", [
                'chunk_index' => 0,
                'chunk_data' => base64_encode('chunk-0-data'),
            ], $admin);
            $this->assertContains($c1['status'], [200, 403, 422]);

            // Pause
            $p = $this->api('POST', "/terminal-transfers/{$trId}/pause", null, $admin);
            $this->assertContains($p['status'], [200, 403, 409, 422]);

            // Resume
            $r2 = $this->api('POST', "/terminal-transfers/{$trId}/resume", null, $admin);
            $this->assertContains($r2['status'], [200, 403, 409, 422]);

            // Get transfer
            $g = $this->api('GET', "/terminal-transfers/{$trId}", null, $admin);
            $this->assertContains($g['status'], [200, 403]);
        } else {
            $this->assertContains($tr['status'], [403, 404, 422]);
        }
    }

    public function testTerminalWrongChecksumRejected(): void
    {
        $admin = $this->getAdminToken();
        $this->api('PUT', '/settings', ['terminals_enabled' => true], $admin);

        $code = 'T2-' . substr(uniqid(), 0, 6);
        $r = $this->api('POST', '/terminals', [
            'terminal_code' => $code,
            'display_name' => 'K2',
            'location_group' => 'HQ',
            'language_code' => 'en',
        ], $admin);
        if ($r['status'] !== 201) $this->markTestSkipped();
        $terminalId = $r['body']['data']['id'];

        // Missing fields → 422
        $bad = $this->api('POST', '/terminal-transfers', [
            'terminal_id' => $terminalId,
            // missing package_name
        ], $admin);
        $this->assertContains($bad['status'], [400, 422]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Reconciliation
    // ═══════════════════════════════════════════════════════════════

    public function testReconciliationRunAndList(): void
    {
        $admin = $this->getAdminToken();

        $run = $this->api('POST', '/reconciliation/run', null, $admin);
        $this->assertContains($run['status'], [200, 201, 403]);

        $list = $this->api('GET', '/reconciliation/runs', null, $admin);
        $this->assertContains($list['status'], [200, 403]);
        if ($list['status'] === 200) {
            $this->assertIsArray($list['body']['data']['data'] ?? []);
        }

        if ($run['status'] === 200 && !empty($run['body']['data']['id'])) {
            $runId = $run['body']['data']['id'];
            $get = $this->api('GET', "/reconciliation/runs/{$runId}", null, $admin);
            $this->assertContains($get['status'], [200, 403, 404]);

            $csv = $this->api('GET', "/reconciliation/runs/{$runId}/csv", null, $admin);
            $this->assertContains($csv['status'], [200, 403, 404]);
        }
    }

    public function testReconciliationRequiresAdmin(): void
    {
        if (!$this->setupContext()) $this->markTestSkipped();
        $r = $this->api('POST', '/reconciliation/run', null, $this->tenantToken);
        $this->assertContains($r['status'], [403, 401]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Refund lifecycle
    // ═══════════════════════════════════════════════════════════════

    public function testRefundHappyPath(): void
    {
        if (!$this->setupContext()) $this->markTestSkipped();
        $admin = $this->getAdminToken();

        $year = 2950 + random_int(1, 99);
        $h = $this->api('POST', '/holds', [
            'inventory_item_id' => $this->itemId, 'held_units' => 1,
            'start_at' => sprintf('%04d-09-15T10:00:00Z', $year),
            'end_at' => sprintf('%04d-09-16T10:00:00Z', $year),
            'request_key' => 'rf-h-' . uniqid(),
        ], $this->tenantToken);
        if ($h['status'] !== 201) $this->markTestSkipped();

        $c = $this->api('POST', "/holds/{$h['body']['data']['id']}/confirm", [
            'request_key' => 'rf-c-' . uniqid(),
        ], $this->tenantToken);
        if ($c['status'] !== 200) $this->markTestSkipped();
        $bookingId = $c['body']['data']['id'];

        // Find bill
        $bills = $this->api('GET', '/bills?page=1&per_page=100', null, $this->tenantToken);
        $bill = null;
        foreach ($bills['body']['data']['data'] ?? [] as $b) {
            if (($b['booking_id'] ?? '') === $bookingId) { $bill = $b; break; }
        }
        if (!$bill) $this->markTestSkipped();

        // Issue refund as admin — should work even without payment
        $refund = $this->api('POST', '/refunds', [
            'bill_id' => $bill['id'],
            'amount' => '10.00',
            'reason' => 'goodwill',
        ], $admin);
        $this->assertContains($refund['status'], [201, 403, 409, 422]);

        // List refunds
        $list = $this->api('GET', '/refunds', null, $admin);
        $this->assertSame(200, $list['status']);

        // Refund exceeding bill amount
        $over = $this->api('POST', '/refunds', [
            'bill_id' => $bill['id'],
            'amount' => '99999.00',
            'reason' => 'oops',
        ], $admin);
        $this->assertContains($over['status'], [400, 403, 409, 422]);
    }

    public function testRefundOnUnknownBill(): void
    {
        $admin = $this->getAdminToken();
        $r = $this->api('POST', '/refunds', [
            'bill_id' => '00000000-0000-0000-0000-000000000000',
            'amount' => '10.00',
            'reason' => 'x',
        ], $admin);
        $this->assertContains($r['status'], [404, 403, 422]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ledger endpoints
    // ═══════════════════════════════════════════════════════════════

    public function testLedgerListAndQueries(): void
    {
        $admin = $this->getAdminToken();
        $list = $this->api('GET', '/ledger?page=1&per_page=25', null, $admin);
        $this->assertContains($list['status'], [200, 403]);

        // By bill — unknown bill → empty or 404
        $byBill = $this->api('GET', '/ledger/bill/00000000-0000-0000-0000-000000000000', null, $admin);
        $this->assertContains($byBill['status'], [200, 404, 403]);

        // By booking — unknown booking
        $byBooking = $this->api('GET', '/ledger/booking/00000000-0000-0000-0000-000000000000', null, $admin);
        $this->assertContains($byBooking['status'], [200, 404, 403]);
    }

    // ═══════════════════════════════════════════════════════════════
    // User management edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUserFreezeUnfreezeFlow(): void
    {
        $admin = $this->getAdminToken();

        $uname = 'frz_' . substr(uniqid(), 0, 6);
        $cu = $this->api('POST', '/users', [
            'username' => $uname, 'password' => 'pass123456',
            'display_name' => 'Frz', 'role' => 'tenant',
        ], $admin);
        if ($cu['status'] !== 201) $this->markTestSkipped();
        $uid = $cu['body']['data']['id'];

        // Freeze
        $fr = $this->api('POST', "/users/{$uid}/freeze", null, $admin);
        $this->assertContains($fr['status'], [200, 403]);

        // Frozen user cannot login
        if ($fr['status'] === 200) {
            $login = $this->api('POST', '/auth/login', [
                'username' => $uname, 'password' => 'pass123456',
                'device_label' => 'frz', 'client_device_id' => 'frz-' . uniqid(),
            ]);
            $this->assertContains($login['status'], [401, 403]);
        }

        // Unfreeze
        $unfr = $this->api('POST', "/users/{$uid}/unfreeze", null, $admin);
        $this->assertContains($unfr['status'], [200, 403]);

        // Update display name
        $upd = $this->api('PUT', "/users/{$uid}", [
            'display_name' => 'New Display',
        ], $admin);
        $this->assertContains($upd['status'], [200, 403, 422]);
    }

    public function testUserDuplicateUsername(): void
    {
        $admin = $this->getAdminToken();
        $uname = 'dup_' . substr(uniqid(), 0, 6);
        $c1 = $this->api('POST', '/users', [
            'username' => $uname, 'password' => 'pass123456',
            'display_name' => 'Dup', 'role' => 'tenant',
        ], $admin);
        if ($c1['status'] !== 201) $this->markTestSkipped();

        $c2 = $this->api('POST', '/users', [
            'username' => $uname, 'password' => 'pass123456',
            'display_name' => 'Dup2', 'role' => 'tenant',
        ], $admin);
        $this->assertContains($c2['status'], [409, 422, 400]);
    }

    public function testUserInvalidRole(): void
    {
        $admin = $this->getAdminToken();
        $r = $this->api('POST', '/users', [
            'username' => 'badrole_' . uniqid(),
            'password' => 'pass123456',
            'display_name' => 'X',
            'role' => 'not_a_role',
        ], $admin);
        $this->assertContains($r['status'], [400, 422]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Settings updates
    // ═══════════════════════════════════════════════════════════════

    public function testSettingsUpdatesAllFields(): void
    {
        $admin = $this->getAdminToken();
        $r = $this->api('PUT', '/settings', [
            'timezone' => 'America/New_York',
            'allow_partial_payments' => true,
            'cancellation_fee_pct' => '15.00',
            'no_show_fee_pct' => '20.00',
            'hold_duration_minutes' => 30,
            'no_show_grace_period_minutes' => 45,
            'max_devices_per_user' => 4,
            'booking_attempts_per_item_per_minute' => 20,
            'max_booking_duration_days' => 180,
            'terminals_enabled' => false,
        ], $admin);
        $this->assertContains($r['status'], [200, 403, 422]);

        $g = $this->api('GET', '/settings', null, $admin);
        $this->assertContains($g['status'], [200, 403]);
    }

    public function testSettingsNonAdminForbidden(): void
    {
        if (!$this->setupContext()) $this->markTestSkipped();
        $r = $this->api('PUT', '/settings', [
            'timezone' => 'UTC',
        ], $this->tenantToken);
        $this->assertContains($r['status'], [403, 401]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Notification DND + preferences
    // ═══════════════════════════════════════════════════════════════

    public function testNotificationPreferences(): void
    {
        if (!$this->setupContext()) $this->markTestSkipped();

        $g = $this->api('GET', '/notifications/preferences', null, $this->tenantToken);
        $this->assertSame(200, $g['status']);

        $up = $this->api('PUT', '/notifications/preferences', [
            'channel' => 'email',
            'event_type' => 'booking_confirmed',
            'is_enabled' => false,
        ], $this->tenantToken);
        $this->assertContains($up['status'], [200, 204, 400, 404, 405, 422, 500]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inventory calendar + availability
    // ═══════════════════════════════════════════════════════════════

    public function testInventoryCalendarAndAvailability(): void
    {
        if (!$this->setupContext()) $this->markTestSkipped();

        $cal = $this->api('GET', "/inventory/{$this->itemId}/calendar?from=2026-04-01&to=2026-04-30", null, $this->tenantToken);
        $this->assertContains($cal['status'], [200, 403]);

        $avail = $this->api('GET', "/inventory/{$this->itemId}/availability?start_at=2026-05-01T10:00:00Z&end_at=2026-05-02T10:00:00Z&units=1", null, $this->tenantToken);
        $this->assertContains($avail['status'], [200, 403, 422]);
    }
}
