<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Terminal endpoint coverage with the terminals_enabled feature flag turned
 * ON. Other integration tests disable terminals by default, which leaves
 * TerminalController's happy-path catch blocks uncovered. This file enables
 * the flag explicitly and walks through every endpoint for real coverage.
 */
class ZTerminalsEnabledHttpTest extends WebTestCase
{
    private ?string $adminToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::ensureKernelShutdown();
    }

    private function api(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $client = static::$booted ? static::getClient() : static::createClient();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token) $server['HTTP_AUTHORIZATION'] = "Bearer {$token}";
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
            'organization_name' => 'TermOrg', 'organization_code' => 'TRM',
            'admin_username' => 'term_admin', 'admin_password' => 'password123',
            'admin_display_name' => 'T',
        ]);
        foreach ([
            ['term_admin', 'password123'],
            ['rbac_admin', 'password123'],
            ['branch_admin', 'password123'],
            ['svc_admin', 'password123'],
            ['xtra_admin', 'password123'],
            ['flows_admin', 'password123'],
            ['http_test_admin', 'secure_pass_123'],
            ['session_cap_admin', 'secure_pass_123'],
            ['uniq_admin', 'secure_pass_123'],
            ['payadmin', 'password123'],
            ['real_http_admin', 'password123'],
            ['admin', 'password123'],
            ['all_ctrl_admin', 'password123'],
            ['e2e_admin', 'e2e_password_123'],
        ] as [$u, $p]) {
            $r = $this->api('POST', '/auth/login', [
                'username' => $u, 'password' => $p,
                'device_label' => 'term', 'client_device_id' => 'term-' . uniqid(),
            ]);
            if ($r['status'] === 200) {
                $this->adminToken = $r['body']['data']['access_token'];
                return $this->adminToken;
            }
        }
        $this->fail('admin login');
    }

    public function testTerminalsFullLifecycleWithFeatureFlag(): void
    {
        $admin = $this->admin();

        // Enable terminals feature
        $s = $this->api('PUT', '/settings', ['terminals_enabled' => true], $admin);
        $this->assertSame(200, $s['status']);
        $this->assertTrue($s['body']['data']['terminals_enabled']);

        // Register a terminal
        $code = 'T-' . bin2hex(random_bytes(4));
        $r = $this->api('POST', '/terminals', [
            'terminal_code' => $code,
            'display_name' => 'Lobby',
            'location_group' => 'HQ',
            'language_code' => 'en',
        ], $admin);
        $this->assertSame(201, $r['status']);
        $terminalId = $r['body']['data']['id'];

        // Get it back
        $g = $this->api('GET', "/terminals/{$terminalId}", null, $admin);
        $this->assertSame(200, $g['status']);
        $this->assertSame($code, $g['body']['data']['terminal_code']);

        // List
        $l = $this->api('GET', '/terminals?page=1&per_page=5', null, $admin);
        $this->assertSame(200, $l['status']);

        // Update
        $u = $this->api('PUT', "/terminals/{$terminalId}", [
            'display_name' => 'Renamed Lobby',
            'language_code' => 'fr',
        ], $admin);
        $this->assertSame(200, $u['status']);
        $this->assertSame('Renamed Lobby', $u['body']['data']['display_name']);
        $this->assertSame('fr', $u['body']['data']['language_code']);

        // Create a playlist
        $pl = $this->api('POST', '/terminal-playlists', [
            'name' => 'Weekday-' . uniqid(),
            'location_group' => 'HQ',
            'schedule_rule' => 'MON-FRI 09:00-17:00',
        ], $admin);
        $this->assertSame(201, $pl['status']);

        // List playlists
        $pll = $this->api('GET', '/terminal-playlists?page=1&per_page=5', null, $admin);
        $this->assertSame(200, $pll['status']);

        // Initiate a transfer (2 chunks)
        $t = $this->api('POST', '/terminal-transfers', [
            'terminal_id' => $terminalId,
            'package_name' => 'pkg-' . uniqid() . '.zip',
            'checksum' => hash('sha256', 'chunk-0chunk-1'),
            'total_chunks' => 2,
        ], $admin);
        $this->assertSame(201, $t['status']);
        $transferId = $t['body']['data']['id'];

        // Upload chunk 0
        $c0 = $this->api('POST', "/terminal-transfers/{$transferId}/chunk", [
            'chunk_index' => 0,
            'chunk_data' => base64_encode('chunk-0'),
        ], $admin);
        $this->assertSame(200, $c0['status']);
        $this->assertSame('in_progress', $c0['body']['data']['status']);

        // Pause mid-transfer
        $p = $this->api('POST', "/terminal-transfers/{$transferId}/pause", null, $admin);
        $this->assertSame(200, $p['status']);
        $this->assertSame('paused', $p['body']['data']['status']);

        // Resume
        $rz = $this->api('POST', "/terminal-transfers/{$transferId}/resume", null, $admin);
        $this->assertSame(200, $rz['status']);
        $this->assertSame('in_progress', $rz['body']['data']['status']);

        // Upload chunk 1 — completes the transfer
        $c1 = $this->api('POST', "/terminal-transfers/{$transferId}/chunk", [
            'chunk_index' => 1,
            'chunk_data' => base64_encode('chunk-1'),
        ], $admin);
        $this->assertSame(200, $c1['status']);
        $this->assertSame('completed', $c1['body']['data']['status']);
        $this->assertSame(2, $c1['body']['data']['transferred_chunks']);

        // Get the completed transfer
        $gt = $this->api('GET', "/terminal-transfers/{$transferId}", null, $admin);
        $this->assertSame(200, $gt['status']);
        $this->assertSame('completed', $gt['body']['data']['status']);
    }

    public function testTerminalErrorBranches(): void
    {
        $admin = $this->admin();
        $this->api('PUT', '/settings', ['terminals_enabled' => true], $admin);

        // Invalid chunk index on non-existent transfer → 404
        $r = $this->api('POST', '/terminal-transfers/00000000-0000-0000-0000-000000000000/chunk', [
            'chunk_index' => 0,
            'chunk_data' => base64_encode('x'),
        ], $admin);
        $this->assertSame(404, $r['status']);

        // Pause non-existent transfer → 404
        $r = $this->api('POST', '/terminal-transfers/00000000-0000-0000-0000-000000000000/pause', null, $admin);
        $this->assertSame(404, $r['status']);

        // Initiate transfer with unknown terminal → 404
        $r = $this->api('POST', '/terminal-transfers', [
            'terminal_id' => '00000000-0000-0000-0000-000000000000',
            'package_name' => 'pkg.zip',
            'checksum' => str_repeat('a', 64),
            'total_chunks' => 1,
        ], $admin);
        $this->assertSame(404, $r['status']);

        // Initiate transfer with bad package name (leading dot) → 400/422
        $this->api('PUT', '/settings', ['terminals_enabled' => true], $admin);
        $code = 'T-' . bin2hex(random_bytes(6));
        $created = $this->api('POST', '/terminals', [
            'terminal_code' => $code,
            'display_name' => 'L',
            'location_group' => 'HQ',
            'language_code' => 'en',
        ], $admin);
        $this->assertSame(201, $created['status'], 'create terminal: ' . json_encode($created['body']));
        $tid = $created['body']['data']['id'];

        $r = $this->api('POST', '/terminal-transfers', [
            'terminal_id' => $tid,
            'package_name' => '../../evil.sh',
            'checksum' => str_repeat('a', 64),
            'total_chunks' => 1,
        ], $admin);
        $this->assertContains($r['status'], [400, 422], 'bad package name: ' . json_encode($r['body']));
    }

    public function testTerminalOperationsRejectedWhenFeatureDisabled(): void
    {
        $admin = $this->admin();

        // Turn terminals OFF
        $this->api('PUT', '/settings', ['terminals_enabled' => false], $admin);

        // Register attempt should 403
        $r = $this->api('POST', '/terminals', [
            'terminal_code' => 'T-OFF',
            'display_name' => 'X',
            'location_group' => 'HQ',
            'language_code' => 'en',
        ], $admin);
        $this->assertSame(403, $r['status']);

        // Re-enable for subsequent tests
        $this->api('PUT', '/settings', ['terminals_enabled' => true], $admin);
    }
}
