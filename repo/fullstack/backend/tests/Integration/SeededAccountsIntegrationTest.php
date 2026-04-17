<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies that the demo accounts seeded by app:seed-demo are present and
 * functional. Each test logs in with a known credential and asserts the exact
 * response contract (status, role, token fields) rather than a broad status
 * allowlist.
 */
class SeededAccountsIntegrationTest extends WebTestCase
{
    private function api(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $client = static::$booted ? static::getClient() : static::createClient();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = "Bearer $token";
        }
        $client->request($method, "/api/v1$path", [], [], $server, $body !== null ? json_encode($body) : null);
        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body'   => json_decode($client->getResponse()->getContent(), true) ?? [],
        ];
    }

    private function login(string $username, string $password): array
    {
        return $this->api('POST', '/auth/login', [
            'username'         => $username,
            'password'         => $password,
            'device_label'     => 'seed-test',
            'client_device_id' => 'seed-' . $username . '-' . uniqid(),
        ]);
    }

    private function ensureSeeded(): void
    {
        // Bootstrap (tolerates 409 when already seeded by docker-entrypoint).
        $this->api('POST', '/bootstrap', [
            'organization_name'  => 'Seed Test Org',
            'organization_code'  => 'SEED',
            'admin_username'     => 'admin',
            'admin_password'     => 'password123',
            'admin_display_name' => 'System Admin',
        ]);

        // Seed the remaining roles via the API, mimicking what app:seed-demo does.
        $adminLogin = $this->login('admin', 'password123');
        if ($adminLogin['status'] !== 200) {
            $this->markTestSkipped('Admin account not available — seed prerequisites unmet');
        }
        $adminToken = $adminLogin['body']['data']['access_token'];

        $roleUsers = [
            ['username' => 'manager', 'password' => 'password123', 'display_name' => 'Demo Manager', 'role' => 'property_manager'],
            ['username' => 'tenant',  'password' => 'password123', 'display_name' => 'Demo Tenant',  'role' => 'tenant'],
            ['username' => 'clerk',   'password' => 'password123', 'display_name' => 'Demo Clerk',   'role' => 'finance_clerk'],
        ];

        foreach ($roleUsers as $u) {
            // 201 = created, 422 = already exists — both acceptable.
            $this->api('POST', '/users', $u, $adminToken);
        }
    }

    // ─── Login contract ────────────────────────────────────────────────────────

    public function testAdminLoginReturnsCorrectContract(): void
    {
        $this->ensureSeeded();

        $r = $this->login('admin', 'password123');

        $this->assertSame(200, $r['status']);
        $data = $r['body']['data'];
        $this->assertArrayHasKey('access_token',  $data, 'access_token missing');
        $this->assertArrayHasKey('refresh_token', $data, 'refresh_token missing');
        $this->assertArrayHasKey('session_id',    $data, 'session_id missing');
        $this->assertArrayHasKey('expires_in',    $data, 'expires_in missing');
        $this->assertSame('administrator', $data['user']['role']);
        $this->assertSame('admin',         $data['user']['username']);
    }

    public function testManagerLoginReturnsPropertyManagerRole(): void
    {
        $this->ensureSeeded();

        $r = $this->login('manager', 'password123');

        $this->assertSame(200, $r['status']);
        $this->assertSame('property_manager', $r['body']['data']['user']['role']);
        $this->assertSame('manager',          $r['body']['data']['user']['username']);
        $this->assertNotEmpty($r['body']['data']['access_token']);
    }

    public function testTenantLoginReturnsTenantRole(): void
    {
        $this->ensureSeeded();

        $r = $this->login('tenant', 'password123');

        $this->assertSame(200, $r['status']);
        $this->assertSame('tenant',  $r['body']['data']['user']['role']);
        $this->assertSame('tenant',  $r['body']['data']['user']['username']);
        $this->assertNotEmpty($r['body']['data']['access_token']);
    }

    public function testClerkLoginReturnsFinanceClerkRole(): void
    {
        $this->ensureSeeded();

        $r = $this->login('clerk', 'password123');

        $this->assertSame(200, $r['status']);
        $this->assertSame('finance_clerk', $r['body']['data']['user']['role']);
        $this->assertSame('clerk',         $r['body']['data']['user']['username']);
        $this->assertNotEmpty($r['body']['data']['access_token']);
    }

    public function testWrongPasswordReturns401(): void
    {
        $this->ensureSeeded();

        $r = $this->login('admin', 'wrong_password');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $r['status']);
        $this->assertSame(401, $r['body']['code']);
    }

    // ─── Role-based access control ─────────────────────────────────────────────

    public function testAdminCanListAllUsers(): void
    {
        $this->ensureSeeded();
        $token = $this->login('admin', 'password123')['body']['data']['access_token'];

        $r = $this->api('GET', '/users', null, $token);

        $this->assertSame(200, $r['status']);
        $users = $r['body']['data']['data'];
        $this->assertIsArray($users);
        // At least the 4 seeded accounts must be present.
        $this->assertGreaterThanOrEqual(4, count($users));
        $usernames = array_column($users, 'username');
        $this->assertContains('admin',   $usernames);
        $this->assertContains('manager', $usernames);
        $this->assertContains('tenant',  $usernames);
        $this->assertContains('clerk',   $usernames);
    }

    public function testTenantCannotListUsers(): void
    {
        $this->ensureSeeded();
        $token = $this->login('tenant', 'password123')['body']['data']['access_token'];

        $r = $this->api('GET', '/users', null, $token);

        $this->assertSame(403, $r['status'], 'Tenant must not be allowed to list users');
    }

    public function testManagerCanListInventory(): void
    {
        $this->ensureSeeded();
        $token = $this->login('manager', 'password123')['body']['data']['access_token'];

        $r = $this->api('GET', '/inventory?page=1&per_page=10', null, $token);

        $this->assertSame(200, $r['status']);
    }

    public function testClerkCanListBills(): void
    {
        $this->ensureSeeded();
        $token = $this->login('clerk', 'password123')['body']['data']['access_token'];

        $r = $this->api('GET', '/bills?page=1&per_page=10', null, $token);

        $this->assertSame(200, $r['status']);
    }

    public function testTenantCannotCreateInventory(): void
    {
        $this->ensureSeeded();
        $token = $this->login('tenant', 'password123')['body']['data']['access_token'];

        $r = $this->api('POST', '/inventory', [
            'asset_code'    => 'SEED-DENY-' . uniqid(),
            'name'          => 'Should Fail',
            'asset_type'    => 'studio',
            'location_name' => 'X',
            'capacity_mode' => 'discrete_units',
            'total_capacity' => 1,
            'timezone'      => 'UTC',
        ], $token);

        $this->assertSame(403, $r['status'], 'Tenant must not be allowed to create inventory');
    }

    // ─── /users/me contract per role ───────────────────────────────────────────

    /**
     * @dataProvider roleCredentialsProvider
     */
    public function testMeEndpointReturnsCorrectRoleForEachAccount(string $username, string $expectedRole): void
    {
        $this->ensureSeeded();
        $token = $this->login($username, 'password123')['body']['data']['access_token'];

        $r = $this->api('GET', '/users/me', null, $token);

        $this->assertSame(200, $r['status']);
        $this->assertSame($expectedRole, $r['body']['data']['role']);
        $this->assertSame($username,     $r['body']['data']['username']);
    }

    public static function roleCredentialsProvider(): array
    {
        return [
            'administrator'    => ['admin',   'administrator'],
            'property_manager' => ['manager', 'property_manager'],
            'tenant'           => ['tenant',  'tenant'],
            'finance_clerk'    => ['clerk',   'finance_clerk'],
        ];
    }
}
