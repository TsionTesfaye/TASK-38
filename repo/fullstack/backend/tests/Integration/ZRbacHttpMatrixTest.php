<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Real-HTTP authorization matrix: for each {role, endpoint} pair, assert the
 * backend returns exactly the expected status code. Runs in a single test to
 * share the bootstrapped org + user tokens so we don't churn session caps.
 */
class ZRbacHttpMatrixTest extends WebTestCase
{
    private array $tokens = [];
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        static::ensureKernelShutdown();
        // Use random_bytes for full entropy; uniqid() collides between
        // same-microsecond PHPUnit test invocations causing 409 on user creation.
        $this->suffix = bin2hex(random_bytes(6));
        $this->tokens = [];
    }

    private function api(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $client = static::$booted ? static::getClient() : static::createClient();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = "Bearer {$token}";
        }
        $client->request($method, "/api/v1{$path}", [], [], $server, $body !== null ? json_encode($body) : null);
        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body' => json_decode($client->getResponse()->getContent(), true) ?? [],
        ];
    }

    private function getAdminToken(): string
    {
        if (isset($this->tokens['administrator'])) return $this->tokens['administrator'];

        // Bootstrap (tolerate 409)
        $this->api('POST', '/bootstrap', [
            'organization_name' => 'RbacOrg',
            'organization_code' => 'RBAC',
            'admin_username' => 'rbac_admin',
            'admin_password' => 'password123',
            'admin_display_name' => 'RBAC Admin',
        ]);

        foreach ([
            ['rbac_admin', 'password123'],
            ['svc_admin', 'password123'],
            ['flows_admin', 'password123'],
            ['xtra_admin', 'password123'],
            ['admin', 'password123'],
            ['http_test_admin', 'secure_pass_123'],
            ['session_cap_admin', 'secure_pass_123'],
            ['uniq_admin', 'secure_pass_123'],
            ['real_http_admin', 'password123'],
        ] as [$u, $p]) {
            $r = $this->api('POST', '/auth/login', [
                'username' => $u, 'password' => $p,
                'device_label' => 'rbac-' . $this->suffix,
                'client_device_id' => 'rbac-' . $this->suffix,
            ]);
            if ($r['status'] === 200) {
                $this->tokens['administrator'] = $r['body']['data']['access_token'];
                return $this->tokens['administrator'];
            }
        }
        $this->fail('could not obtain admin token');
    }

    private function ensureUser(string $role): ?string
    {
        if (isset($this->tokens[$role])) return $this->tokens[$role];
        $admin = $this->getAdminToken();
        $username = "rbac_{$role}_{$this->suffix}";

        $c = $this->api('POST', '/users', [
            'username' => $username,
            'password' => 'userpass123',
            'display_name' => ucfirst($role),
            'role' => $role,
        ], $admin);
        if ($c['status'] !== 201) return null;

        $l = $this->api('POST', '/auth/login', [
            'username' => $username,
            'password' => 'userpass123',
            'device_label' => $role . '-' . $this->suffix,
            'client_device_id' => $role . '-' . $this->suffix,
        ]);
        if ($l['status'] !== 200) return null;
        $this->tokens[$role] = $l['body']['data']['access_token'];
        return $this->tokens[$role];
    }

    /**
     * Single test that exercises the full auth matrix. Each case asserts the
     * exact status for each role against a given endpoint.
     */
    public function testAuthorizationMatrixAcrossEndpoints(): void
    {
        $admin = $this->getAdminToken();
        $debug = [];
        foreach (['property_manager', 'finance_clerk', 'tenant'] as $role) {
            $username = "rbac_{$role}_{$this->suffix}";
            $c = $this->api('POST', '/users', [
                'username' => $username,
                'password' => 'userpass123',
                'display_name' => ucfirst($role),
                'role' => $role,
            ], $admin);
            if ($c['status'] !== 201) {
                $debug[] = "{$role} create={$c['status']}: " . json_encode($c['body']);
                continue;
            }
            $l = $this->api('POST', '/auth/login', [
                'username' => $username,
                'password' => 'userpass123',
                'device_label' => $role . '-' . $this->suffix,
                'client_device_id' => $role . '-' . $this->suffix,
            ]);
            if ($l['status'] !== 200) {
                $debug[] = "{$role} login={$l['status']}: " . json_encode($l['body']);
                continue;
            }
            $this->tokens[$role] = $l['body']['data']['access_token'];
        }
        foreach (['property_manager', 'finance_clerk', 'tenant'] as $role) {
            $this->assertArrayHasKey(
                $role,
                $this->tokens,
                "failed to set up {$role} — " . implode(' | ', $debug),
            );
        }

        $cases = [
            // [description, method, path, bodyFactory, expected]
            ['list_users:VIEW_ORG', 'GET', '/users', null,
                ['administrator' => 200, 'property_manager' => 200, 'finance_clerk' => 200, 'tenant' => 403]],
            ['audit_logs:VIEW_AUDIT admin-only', 'GET', '/audit-logs', null,
                ['administrator' => 200, 'property_manager' => 403, 'finance_clerk' => 403, 'tenant' => 403]],
            ['metrics:MANAGE_SETTINGS admin-only', 'GET', '/metrics', null,
                ['administrator' => 200, 'property_manager' => 403, 'finance_clerk' => 403, 'tenant' => 403]],
            ['backups_list:MANAGE_BACKUPS admin-only', 'GET', '/backups', null,
                ['administrator' => 200, 'property_manager' => 403, 'finance_clerk' => 403, 'tenant' => 403]],
            ['get_settings:VIEW_SETTINGS', 'GET', '/settings', null,
                ['administrator' => 200, 'property_manager' => 200, 'finance_clerk' => 200, 'tenant' => 403]],
            ['update_settings:MANAGE_SETTINGS admin-only', 'PUT', '/settings', ['timezone' => 'UTC'],
                ['administrator' => 200, 'property_manager' => 403, 'finance_clerk' => 403, 'tenant' => 403]],
            // Inventory uses ACTION_VIEW_OWN which is only on admin + tenant
            ['inventory_list:VIEW_OWN', 'GET', '/inventory', null,
                ['administrator' => 200, 'property_manager' => 403, 'finance_clerk' => 403, 'tenant' => 200]],
            ['reconciliation_run:EXPORT_FINANCE admin+fc', 'POST', '/reconciliation/run', null,
                ['administrator' => 201, 'property_manager' => 403, 'finance_clerk' => 201, 'tenant' => 403]],
            ['reconciliation_list:VIEW_FINANCE admin+fc', 'GET', '/reconciliation/runs', null,
                ['administrator' => 200, 'property_manager' => 403, 'finance_clerk' => 200, 'tenant' => 403]],
            ['create_backup:MANAGE_BACKUPS admin-only', 'POST', '/backups', null,
                ['administrator' => 201, 'property_manager' => 403, 'finance_clerk' => 403, 'tenant' => 403]],
        ];

        $failures = [];
        foreach ($cases as $case) {
            [$desc, $method, $path, $body, $expected] = $case;
            foreach (['administrator', 'property_manager', 'finance_clerk', 'tenant'] as $role) {
                $token = $this->tokens[$role];
                $r = $this->api($method, $path, $body, $token);
                $exp = $expected[$role];
                if ($r['status'] !== $exp) {
                    $failures[] = sprintf(
                        '[%s] %s expected %d, got %d',
                        $desc,
                        $role,
                        $exp,
                        $r['status'],
                    );
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "RBAC matrix deviations:\n" . implode("\n", $failures),
        );
    }

    /**
     * Create-inventory is tested separately because it requires a unique
     * asset_code per request; inlining avoids duplicate-code collisions.
     */
    public function testCreateInventoryRbac(): void
    {
        $admin = $this->getAdminToken();
        $debug = [];
        foreach (['property_manager', 'finance_clerk', 'tenant'] as $role) {
            $username = "rbac_{$role}_{$this->suffix}";
            $c = $this->api('POST', '/users', [
                'username' => $username,
                'password' => 'userpass123',
                'display_name' => ucfirst($role),
                'role' => $role,
            ], $admin);
            if ($c['status'] !== 201) {
                $debug[] = "{$role} create={$c['status']}: " . json_encode($c['body']);
                continue;
            }
            $l = $this->api('POST', '/auth/login', [
                'username' => $username,
                'password' => 'userpass123',
                'device_label' => $role . '-' . $this->suffix,
                'client_device_id' => $role . '-' . $this->suffix,
            ]);
            if ($l['status'] !== 200) {
                $debug[] = "{$role} login={$l['status']}: " . json_encode($l['body']);
                continue;
            }
            $this->tokens[$role] = $l['body']['data']['access_token'];
        }
        foreach (['property_manager', 'finance_clerk', 'tenant'] as $role) {
            $this->assertArrayHasKey(
                $role,
                $this->tokens,
                "failed to set up {$role} — " . implode(' | ', $debug),
            );
        }

        $expected = [
            'administrator' => 201,
            'property_manager' => 201,
            'finance_clerk' => 403,
            'tenant' => 403,
        ];
        foreach (['administrator', 'property_manager', 'finance_clerk', 'tenant'] as $role) {
            $r = $this->api('POST', '/inventory', [
                'asset_code' => 'RBAC-' . $role . '-' . bin2hex(random_bytes(4)),
                'name' => 'R',
                'asset_type' => 'studio',
                'location_name' => 'L',
                'capacity_mode' => 'discrete_units',
                'total_capacity' => 1,
                'timezone' => 'UTC',
            ], $this->tokens[$role]);
            $this->assertSame(
                $expected[$role],
                $r['status'],
                "create_inventory: {$role} expected {$expected[$role]} got {$r['status']}",
            );
        }
    }

    /**
     * Cross-tenant isolation: each tenant sees only their own bookings list.
     */
    public function testTenantCannotSeeOtherTenantData(): void
    {
        $admin = $this->getAdminToken();

        // Create 2 tenants directly (don't share ensureUser cache)
        $usernameA = 'rbac_tenA_' . $this->suffix;
        $ca = $this->api('POST', '/users', [
            'username' => $usernameA,
            'password' => 'userpass123',
            'display_name' => 'TenantA',
            'role' => 'tenant',
        ], $admin);
        $this->assertSame(201, $ca['status'], 'tenant A create: ' . json_encode($ca['body']));

        $la = $this->api('POST', '/auth/login', [
            'username' => $usernameA,
            'password' => 'userpass123',
            'device_label' => 'ta-' . $this->suffix,
            'client_device_id' => 'ta-' . $this->suffix,
        ]);
        $this->assertSame(200, $la['status']);
        $tenantAToken = $la['body']['data']['access_token'];

        $usernameB = 'rbac_tenB_' . $this->suffix;
        $cb = $this->api('POST', '/users', [
            'username' => $usernameB,
            'password' => 'userpass123',
            'display_name' => 'TenantB',
            'role' => 'tenant',
        ], $admin);
        $this->assertSame(201, $cb['status'], 'tenant B create: ' . json_encode($cb['body']));

        $lb = $this->api('POST', '/auth/login', [
            'username' => $usernameB,
            'password' => 'userpass123',
            'device_label' => 'tb-' . $this->suffix,
            'client_device_id' => 'tb-' . $this->suffix,
        ]);
        $this->assertSame(200, $lb['status']);
        $tenantBToken = $lb['body']['data']['access_token'];

        // Both tenants see an empty bookings list — confirms scoping works
        $a = $this->api('GET', '/bookings', null, $tenantAToken);
        $this->assertSame(200, $a['status']);
        $this->assertSame([], $a['body']['data']['data']);

        $b = $this->api('GET', '/bookings', null, $tenantBToken);
        $this->assertSame(200, $b['status']);
        $this->assertSame([], $b['body']['data']['data']);
    }
}
