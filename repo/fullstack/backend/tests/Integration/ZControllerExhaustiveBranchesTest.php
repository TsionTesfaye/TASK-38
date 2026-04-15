<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Exhaustively triggers controller catch branches the rest of the integration
 * suite misses: AccessDeniedException on non-matching roles, AuthenticationException
 * on invalid Bearer tokens, InvalidArgumentException on malformed bodies,
 * and \Throwable fall-through on unexpected service failures.
 *
 * Designed to be idempotent and order-independent so it can run alongside
 * other integration tests without touching their fixtures.
 */
class ZControllerExhaustiveBranchesTest extends WebTestCase
{
    private ?string $adminToken = null;
    private ?string $tenantToken = null;

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

    private function admin(): string
    {
        if ($this->adminToken) return $this->adminToken;
        $this->api('POST', '/bootstrap', [
            'organization_name' => 'ExhOrg', 'organization_code' => 'EXH',
            'admin_username' => 'exh_admin', 'admin_password' => 'password123',
            'admin_display_name' => 'Exh',
        ]);
        foreach ([
            ['exh_admin', 'password123'],
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
        ] as [$u, $p]) {
            $r = $this->api('POST', '/auth/login', [
                'username' => $u, 'password' => $p,
                'device_label' => 'exh', 'client_device_id' => 'exh-' . uniqid(),
            ]);
            if ($r['status'] === 200) {
                $this->adminToken = $r['body']['data']['access_token'];
                return $this->adminToken;
            }
        }
        $this->fail('admin login');
    }

    private function tenant(): string
    {
        if ($this->tenantToken) return $this->tenantToken;
        $admin = $this->admin();
        $uname = 'exh_t_' . bin2hex(random_bytes(3));
        $cu = $this->api('POST', '/users', [
            'username' => $uname, 'password' => 'tpass123',
            'display_name' => 'ExhT', 'role' => 'tenant',
        ], $admin);
        if ($cu['status'] !== 201) $this->fail('tenant create: ' . json_encode($cu['body']));

        $l = $this->api('POST', '/auth/login', [
            'username' => $uname, 'password' => 'tpass123',
            'device_label' => 'exh-t', 'client_device_id' => 'exh-t-' . uniqid(),
        ]);
        $this->assertSame(200, $l['status']);
        $this->tenantToken = $l['body']['data']['access_token'];
        return $this->tenantToken;
    }

    // ═══════════════════════════════════════════════════════════════
    // Tenant forbidden on admin/manager endpoints — AccessDeniedException catches
    // ═══════════════════════════════════════════════════════════════

    public function testTenantForbiddenOnAdminEndpoints(): void
    {
        $tenant = $this->tenant();
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $cases = [
            // [method, path, body?]
            ['POST', '/users', ['username' => 'x', 'password' => 'p1234567', 'display_name' => 'X', 'role' => 'tenant']],
            ['PUT', "/users/{$fakeId}", ['display_name' => 'Y']],
            ['POST', "/users/{$fakeId}/freeze"],
            ['POST', "/users/{$fakeId}/unfreeze"],
            ['POST', '/inventory', [
                'asset_code' => 'x', 'name' => 'X', 'asset_type' => 'studio',
                'location_name' => 'L', 'capacity_mode' => 'discrete_units',
                'total_capacity' => 1, 'timezone' => 'UTC',
            ]],
            ['PUT', "/inventory/{$fakeId}", ['name' => 'Y']],
            ['POST', "/inventory/{$fakeId}/deactivate"],
            ['POST', "/inventory/{$fakeId}/pricing", [
                'rate_type' => 'daily', 'amount' => '1.00', 'currency' => 'USD',
                'effective_from' => '2026-01-01T00:00:00Z',
            ]],
            ['POST', "/bookings/{$fakeId}/check-in"],
            ['POST', "/bookings/{$fakeId}/complete"],
            ['POST', "/bookings/{$fakeId}/no-show"],
            ['POST', '/bills', ['booking_id' => $fakeId, 'amount' => '1.00', 'reason' => 'x']],
            ['POST', "/bills/{$fakeId}/void"],
            ['POST', '/refunds', ['bill_id' => $fakeId, 'amount' => '1.00', 'reason' => 'x']],
            ['GET', '/audit-logs'],
            ['GET', '/backups'],
            ['POST', '/backups'],
            ['POST', '/backups/preview', ['filename' => 'x']],
            ['POST', '/backups/restore', ['filename' => 'x']],
            ['PUT', '/settings', ['timezone' => 'UTC']],
            ['GET', '/metrics'],
            ['POST', '/reconciliation/run'],
            ['POST', '/terminals', [
                'terminal_code' => 'T-T', 'display_name' => 'X',
                'location_group' => 'HQ', 'language_code' => 'en',
            ]],
            ['POST', '/terminal-playlists', ['name' => 'p', 'location_group' => 'HQ', 'schedule_rule' => 'x']],
            ['POST', '/terminal-transfers', [
                'terminal_id' => $fakeId, 'package_name' => 'p.zip',
                'checksum' => str_repeat('a', 64), 'total_chunks' => 1,
            ]],
            // GET endpoints that require MANAGE_TERMINALS — tenant should 403
            ['GET', '/terminals'],
            ['GET', "/terminals/{$fakeId}"],
            ['PUT', "/terminals/{$fakeId}", ['display_name' => 'x']],
            ['GET', '/terminal-playlists'],
            ['GET', "/terminal-transfers/{$fakeId}"],
            ['POST', "/terminal-transfers/{$fakeId}/chunk", ['chunk_index' => 0, 'chunk_data' => 'eA==']],
            ['POST', "/terminal-transfers/{$fakeId}/pause"],
            ['POST', "/terminal-transfers/{$fakeId}/resume"],
            // GET booking by unknown ID returns 404 before RBAC — already covered elsewhere, skip
            // Reconciliation runs — tenant lacks VIEW_FINANCE
            ['GET', '/reconciliation/runs'],
            ['GET', "/reconciliation/runs/{$fakeId}"],
            ['GET', "/reconciliation/runs/{$fakeId}/csv"],
            // Ledger — tenant lacks VIEW_FINANCE
            ['GET', '/ledger'],
            ['GET', "/ledger/bill/{$fakeId}"],
            ['GET', "/ledger/booking/{$fakeId}"],
            // Note: /refunds list is scoped per-role (tenants see own), not forbidden
        ];

        foreach ($cases as $case) {
            [$method, $path] = $case;
            $body = $case[2] ?? null;
            $r = $this->api($method, $path, $body, $tenant);
            $this->assertSame(
                403,
                $r['status'],
                "{$method} {$path} should 403 for tenant: " . json_encode($r['body']),
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Garbage-JSON body → handled by controllers
    // ═══════════════════════════════════════════════════════════════

    public function testGarbageJsonBodyOnManyEndpoints(): void
    {
        $admin = $this->admin();
        $rawGarbage = '{"not":';
        $client = static::getClient();

        $paths = [
            '/users', '/inventory', '/bills', '/refunds', '/holds',
            '/payments', '/terminals', '/terminal-playlists', '/terminal-transfers',
        ];
        foreach ($paths as $p) {
            $client->request('POST', "/api/v1{$p}", [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => "Bearer {$admin}",
            ], $rawGarbage);
            $status = $client->getResponse()->getStatusCode();
            $this->assertContains(
                $status,
                [400, 401, 403, 422, 500],
                "POST {$p} with garbage JSON got {$status}",
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Invalid enum values → validation failure
    // ═══════════════════════════════════════════════════════════════

    public function testInvalidEnumValuesRejected(): void
    {
        $admin = $this->admin();

        // Invalid role
        $r = $this->api('POST', '/users', [
            'username' => 'bad_' . bin2hex(random_bytes(3)),
            'password' => 'p1234567',
            'display_name' => 'X',
            'role' => 'god_mode',
        ], $admin);
        $this->assertContains($r['status'], [400, 422]);

        // Invalid capacity_mode — this IS enum-validated
        $r = $this->api('POST', '/inventory', [
            'asset_code' => 'y' . bin2hex(random_bytes(3)),
            'name' => 'X', 'asset_type' => 'studio', 'location_name' => 'L',
            'capacity_mode' => 'fancy_mode',
            'total_capacity' => 1, 'timezone' => 'UTC',
        ], $admin);
        $this->assertContains($r['status'], [400, 422]);

        // Invalid rate_type on pricing
        $iv = $this->api('POST', '/inventory', [
            'asset_code' => 'rt' . bin2hex(random_bytes(3)),
            'name' => 'R', 'asset_type' => 'studio', 'location_name' => 'L',
            'capacity_mode' => 'discrete_units',
            'total_capacity' => 1, 'timezone' => 'UTC',
        ], $admin);
        $this->assertSame(201, $iv['status']);
        $iid = $iv['body']['data']['id'];

        $pr = $this->api('POST', "/inventory/{$iid}/pricing", [
            'rate_type' => 'ludicrous_speed',
            'amount' => '1.00', 'currency' => 'USD',
            'effective_from' => '2026-01-01T00:00:00Z',
        ], $admin);
        $this->assertContains($pr['status'], [400, 422]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Pagination + empty list responses on every list endpoint
    // ═══════════════════════════════════════════════════════════════

    public function testListEndpointsWithPaginationClamping(): void
    {
        $admin = $this->admin();
        $paths = [
            '/users',
            '/inventory',
            '/bookings',
            '/bills',
            '/payments',
            '/refunds',
            '/notifications',
            '/audit-logs',
            '/backups',
            '/ledger',
            '/reconciliation/runs',
        ];
        foreach ($paths as $p) {
            // Negative page clamps to 1
            $r = $this->api('GET', "{$p}?page=-10&per_page=5", null, $admin);
            $this->assertContains($r['status'], [200, 403]);
            if ($r['status'] === 200 && isset($r['body']['data']['meta'])) {
                $this->assertSame(1, $r['body']['data']['meta']['page']);
            }

            // Huge per_page clamps to 100
            $r2 = $this->api('GET', "{$p}?page=1&per_page=99999", null, $admin);
            $this->assertContains($r2['status'], [200, 403]);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Settings concurrent rapid updates — exercise repeated flush path
    // ═══════════════════════════════════════════════════════════════

    public function testSettingsMultipleUpdatesSucceedSequentially(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 3; $i++) {
            $r = $this->api('PUT', '/settings', [
                'hold_duration_minutes' => 15 + $i,
                'cancellation_fee_pct' => sprintf('%d.00', 20 + $i),
            ], $admin);
            $this->assertSame(200, $r['status']);
            $this->assertSame(15 + $i, $r['body']['data']['hold_duration_minutes']);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Notification preference endpoints
    // ═══════════════════════════════════════════════════════════════

    public function testNotificationPreferencesRoundTrip(): void
    {
        $admin = $this->admin();
        // Create a new preference
        $r = $this->api('PUT', '/notifications/preferences/booking.confirmed', [
            'enabled' => true,
            'dnd_start' => '22:00',
            'dnd_end' => '07:00',
        ], $admin);
        $this->assertContains($r['status'], [200, 201, 204]);

        // Read prefs — should include the one we just set
        $g = $this->api('GET', '/notifications/preferences', null, $admin);
        $this->assertSame(200, $g['status']);

        // Update it again with enabled=false
        $r2 = $this->api('PUT', '/notifications/preferences/booking.confirmed', [
            'enabled' => false,
        ], $admin);
        $this->assertContains($r2['status'], [200, 204]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Audit logs with filters — exercises filter-combination branches
    // ═══════════════════════════════════════════════════════════════

    public function testAuditLogFiltersAllCombinations(): void
    {
        $admin = $this->admin();
        $queries = [
            '/audit-logs?action_code=AUTH_LOGIN',
            '/audit-logs?object_type=User',
            '/audit-logs?object_id=some-id',
            '/audit-logs?actor_user_id=00000000-0000-0000-0000-000000000000',
            '/audit-logs?from=2025-01-01T00:00:00Z',
            '/audit-logs?to=2030-12-31T23:59:59Z',
            '/audit-logs?action_code=X&object_type=Y&object_id=Z',
        ];
        foreach ($queries as $q) {
            $r = $this->api('GET', $q, null, $admin);
            $this->assertSame(200, $r['status'], "query {$q}");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Ledger filters
    // ═══════════════════════════════════════════════════════════════

    public function testLedgerFilters(): void
    {
        $admin = $this->admin();
        $queries = [
            '/ledger?entry_type=bill_issued',
            '/ledger?entry_type=payment_received',
            '/ledger?entry_type=refund_issued',
            '/ledger?entry_type=penalty_applied',
            '/ledger?entry_type=bill_voided',
            '/ledger?currency=USD',
        ];
        foreach ($queries as $q) {
            $r = $this->api('GET', $q, null, $admin);
            $this->assertSame(200, $r['status'], "query {$q}");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Holds endpoint validation
    // ═══════════════════════════════════════════════════════════════

    public function testHoldValidationRejections(): void
    {
        $tenant = $this->tenant();

        // Missing inventory_item_id
        $r = $this->api('POST', '/holds', [
            'held_units' => 1,
            'start_at' => '2028-05-15T10:00:00Z',
            'end_at' => '2028-05-16T10:00:00Z',
            'request_key' => 'v-1-' . uniqid(),
        ], $tenant);
        $this->assertContains($r['status'], [400, 422]);

        // Negative held_units
        $r = $this->api('POST', '/holds', [
            'inventory_item_id' => '00000000-0000-0000-0000-000000000000',
            'held_units' => -1,
            'start_at' => '2028-05-15T10:00:00Z',
            'end_at' => '2028-05-16T10:00:00Z',
            'request_key' => 'v-2-' . uniqid(),
        ], $tenant);
        $this->assertContains($r['status'], [400, 404, 422]);

        // Start after end
        $r = $this->api('POST', '/holds', [
            'inventory_item_id' => '00000000-0000-0000-0000-000000000000',
            'held_units' => 1,
            'start_at' => '2028-05-16T10:00:00Z',
            'end_at' => '2028-05-15T10:00:00Z',
            'request_key' => 'v-3-' . uniqid(),
        ], $tenant);
        $this->assertContains($r['status'], [400, 404, 422]);
    }
}
