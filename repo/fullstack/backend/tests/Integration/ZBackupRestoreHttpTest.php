<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Strict HTTP-level contract tests for POST /api/v1/backups and POST /api/v1/backups/restore.
 *
 * Covers:
 *  - Exact 401 (unauthenticated) with response body fields
 *  - Exact 403 (wrong role: tenant) with response body fields
 *  - Exact 422 (empty filename) with code + message fields
 *  - Exact 404 (nonexistent backup file) with code + message fields
 *  - Admin success path: create → list → preview → restore (if storage available)
 */
class ZBackupRestoreHttpTest extends WebTestCase
{
    private ?string $adminToken = null;
    private ?string $tenantToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::ensureKernelShutdown();
    }

    private function api(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $client = static::$booted ? static::getClient() : static::createClient();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = "Bearer $token";
        }
        $client->request($method, "/api/v1$path", [], [], $server, $body !== null ? json_encode($body) : null);
        return [
            'status'  => $client->getResponse()->getStatusCode(),
            'body'    => json_decode($client->getResponse()->getContent(), true) ?? [],
            'content' => $client->getResponse()->getContent(),
        ];
    }

    private function admin(): string
    {
        if ($this->adminToken) return $this->adminToken;

        $this->api('POST', '/bootstrap', [
            'organization_name'  => 'BackupHttpOrg',
            'organization_code'  => 'BHO',
            'admin_username'     => 'bho_admin',
            'admin_password'     => 'password123',
            'admin_display_name' => 'BHO Admin',
        ]);

        foreach ([
            ['bho_admin',       'password123'],
            ['admin',           'password123'],
            ['all_ctrl_admin',  'password123'],
            ['e2e_admin',       'e2e_password_123'],
            ['http_test_admin', 'secure_pass_123'],
            ['payadmin',        'password123'],
        ] as [$u, $p]) {
            $r = $this->api('POST', '/auth/login', [
                'username'         => $u,
                'password'         => $p,
                'device_label'     => 'bhttp',
                'client_device_id' => 'bhttp-' . uniqid(),
            ]);
            if ($r['status'] === 200) {
                $this->adminToken = $r['body']['data']['access_token'];
                return $this->adminToken;
            }
        }

        $this->fail('No admin login succeeded for ZBackupRestoreHttpTest');
    }

    private function tenant(): string
    {
        if ($this->tenantToken) return $this->tenantToken;

        $admin = $this->admin();
        $uname = 'bh_tenant_' . bin2hex(random_bytes(3));

        $this->api('POST', '/users', [
            'username'     => $uname,
            'password'     => 'tenpass123',
            'display_name' => 'BH Tenant',
            'role'         => 'tenant',
        ], $admin);

        $l = $this->api('POST', '/auth/login', [
            'username'         => $uname,
            'password'         => 'tenpass123',
            'device_label'     => 'bh-t',
            'client_device_id' => 'bh-t-' . uniqid(),
        ]);
        $this->assertSame(200, $l['status'], 'Tenant login for backup test setup');
        $this->tenantToken = $l['body']['data']['access_token'];
        return $this->tenantToken;
    }

    // ─── 401 Unauthenticated ──────────────────────────────────────────────────

    public function testCreateBackupWithoutAuthReturns401(): void
    {
        $r = $this->api('POST', '/backups');

        $this->assertSame(401, $r['status']);
        $this->assertSame(401, $r['body']['code']);
        $this->assertIsString($r['body']['message']);
    }

    public function testListBackupsWithoutAuthReturns401(): void
    {
        $r = $this->api('GET', '/backups');

        $this->assertSame(401, $r['status']);
        $this->assertSame(401, $r['body']['code']);
    }

    public function testRestoreWithoutAuthReturns401(): void
    {
        $r = $this->api('POST', '/backups/restore', ['filename' => 'backup_org_20260101_000000.enc']);

        $this->assertSame(401, $r['status']);
        $this->assertSame(401, $r['body']['code']);
        $this->assertIsString($r['body']['message']);
    }

    public function testPreviewWithoutAuthReturns401(): void
    {
        $r = $this->api('POST', '/backups/preview', ['filename' => 'backup_org_20260101_000000.enc']);

        $this->assertSame(401, $r['status']);
        $this->assertSame(401, $r['body']['code']);
    }

    // ─── 403 Wrong role (tenant) ──────────────────────────────────────────────

    public function testCreateBackupAsTenantReturns403(): void
    {
        $token = $this->tenant();
        $r = $this->api('POST', '/backups', null, $token);

        $this->assertSame(403, $r['status']);
        $this->assertSame(403, $r['body']['code']);
    }

    public function testListBackupsAsTenantReturns403(): void
    {
        $token = $this->tenant();
        $r = $this->api('GET', '/backups', null, $token);

        $this->assertSame(403, $r['status']);
        $this->assertSame(403, $r['body']['code']);
    }

    public function testRestoreAsTenantReturns403(): void
    {
        $token = $this->tenant();
        $r = $this->api('POST', '/backups/restore', [
            'filename' => 'backup_org_20260101_000000.enc',
        ], $token);

        $this->assertSame(403, $r['status']);
        $this->assertSame(403, $r['body']['code']);
    }

    // ─── 422 Validation failures ──────────────────────────────────────────────

    public function testRestoreWithEmptyFilenameReturns422(): void
    {
        $admin = $this->admin();
        $r = $this->api('POST', '/backups/restore', ['filename' => ''], $admin);

        $this->assertSame(422, $r['status']);
        $this->assertSame(422, $r['body']['code']);
        $this->assertStringContainsString('filename', $r['body']['message']);
    }

    public function testRestoreWithMissingFilenameReturns422(): void
    {
        $admin = $this->admin();
        $r = $this->api('POST', '/backups/restore', [], $admin);

        $this->assertSame(422, $r['status']);
        $this->assertSame(422, $r['body']['code']);
        $this->assertStringContainsString('filename', $r['body']['message']);
    }

    public function testPreviewWithEmptyFilenameReturns422(): void
    {
        $admin = $this->admin();
        $r = $this->api('POST', '/backups/preview', ['filename' => ''], $admin);

        $this->assertSame(422, $r['status']);
        $this->assertSame(422, $r['body']['code']);
        $this->assertStringContainsString('filename', $r['body']['message']);
    }

    // ─── 404 Nonexistent backup ───────────────────────────────────────────────

    public function testRestoreNonexistentFilenameReturns404(): void
    {
        $admin = $this->admin();
        // Valid format (passes filename regex) but file does not exist
        $r = $this->api('POST', '/backups/restore', [
            'filename' => 'backup_doesnotexist-org_20200101_000000.enc',
        ], $admin);

        $this->assertSame(404, $r['status']);
        $this->assertSame(404, $r['body']['code']);
    }

    public function testPreviewNonexistentFilenameReturns404(): void
    {
        $admin = $this->admin();
        $r = $this->api('POST', '/backups/preview', [
            'filename' => 'backup_doesnotexist-org_20200101_000000.enc',
        ], $admin);

        $this->assertSame(404, $r['status']);
        $this->assertSame(404, $r['body']['code']);
    }

    // ─── Admin success path ───────────────────────────────────────────────────

    public function testAdminCreateBackupReturns201WithRequiredFields(): void
    {
        $admin = $this->admin();
        $r = $this->api('POST', '/backups', null, $admin);

        $this->assertSame(201, $r['status']);
        $data = $r['body']['data'];
        $this->assertStringStartsWith('backup_', $data['filename'], 'filename must start with backup_');
        $this->assertIsArray($data['tables'], 'tables must be an array');
        $this->assertArrayHasKey('created_at', $data);
    }

    public function testAdminListBackupsReturns200(): void
    {
        $admin = $this->admin();

        // Ensure at least one backup exists
        $this->api('POST', '/backups', null, $admin);

        $r = $this->api('GET', '/backups', null, $admin);

        $this->assertSame(200, $r['status']);
        // Response is paginated: {data: [...], meta: {...}}
        $this->assertArrayHasKey('data', $r['body']);
        $this->assertIsArray($r['body']['data']);
        $this->assertNotEmpty($r['body']['data'], 'At least one backup must exist after create');
        $first = $r['body']['data'][0];
        $this->assertArrayHasKey('filename', $first);
        $this->assertArrayHasKey('size_bytes', $first);
        $this->assertArrayHasKey('modified_at', $first);
    }

    public function testAdminCreateThenPreviewBackup(): void
    {
        $admin = $this->admin();
        $create = $this->api('POST', '/backups', null, $admin);
        $this->assertSame(201, $create['status']);
        $filename = $create['body']['data']['filename'];

        $preview = $this->api('POST', '/backups/preview', ['filename' => $filename], $admin);
        $this->assertSame(200, $preview['status']);
        $data = $preview['body']['data'];
        $this->assertArrayHasKey('metadata', $data);
        $this->assertArrayHasKey('record_counts', $data);
        $this->assertIsArray($data['record_counts']);
        $this->assertIsArray($data['metadata']);
        $this->assertArrayHasKey('organization_id', $data['metadata']);
    }

    public function testAdminCreateThenRestoreBackup(): void
    {
        $admin = $this->admin();
        $create = $this->api('POST', '/backups', null, $admin);
        $this->assertSame(201, $create['status']);
        $filename = $create['body']['data']['filename'];

        $restore = $this->api('POST', '/backups/restore', ['filename' => $filename], $admin);
        $this->assertSame(200, $restore['status']);
        $data = $restore['body']['data'];
        $this->assertSame($filename, $data['filename']);
        $this->assertArrayHasKey('restored_counts', $data);
        $this->assertIsArray($data['restored_counts']);
    }
}
