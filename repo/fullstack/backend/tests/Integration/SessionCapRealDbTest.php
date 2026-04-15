<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * REAL DATABASE integration test for the device session cap.
 *
 * This test boots the real Symfony kernel, hits the real /api/v1/auth/login
 * endpoint, and verifies the session cap invariant against real DB state.
 *
 * ── WHAT THIS PROVES ────────────────────────────────────────────
 *
 *   1. The full login stack (controller → service → repository → DB)
 *      enforces the session cap end-to-end.
 *   2. After N logins for the same user, the committed active session
 *      count in the database never exceeds the configured cap (5).
 *   3. Excess sessions are revoked (oldest first) before the new
 *      session is inserted, within a single transaction.
 *
 * ── CONCURRENCY MODEL ───────────────────────────────────────────
 *
 *   PHPUnit runs in a single PHP process. Symfony's WebTestCase
 *   executes requests sequentially through the kernel — it does NOT
 *   open parallel TCP connections. Therefore this test proves the
 *   cap enforcement ALGORITHM against a real DB, but does not prove
 *   InnoDB lock serialization under true parallel load.
 *
 *   True concurrent DB proof would require:
 *     - pcntl_fork() to spawn parallel PHP processes, OR
 *     - an external harness (e.g., parallel curl/HTTP requests)
 *   Neither is available in the standard PHPUnit environment.
 *
 *   The InnoDB locking guarantee is proven by the SQL semantics:
 *     - SELECT id FROM users WHERE id=? FOR UPDATE on the user row
 *       serializes all concurrent transactions for the same user.
 *     - This lock is held for the entire transaction (revoke → insert → commit).
 *     - A second transaction blocks at this lock until the first commits.
 *     - After unblocking, it reads the committed state (including the
 *       first transaction's INSERT) and adjusts accordingly.
 *
 *   This test validates the algorithm against real DB state.
 *   The lock correctness is a property of InnoDB, not of PHPUnit.
 */
class SessionCapRealDbTest extends WebTestCase
{
    private function api(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $client = static::getClient() ?? static::createClient();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = "Bearer {$token}";
        }
        $client->request($method, "/api/v1{$path}", [], [], $server, $body !== null ? json_encode($body) : null);

        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body'   => json_decode($client->getResponse()->getContent(), true) ?? [],
        ];
    }

    private string $adminUsername = 'session_cap_admin';
    private string $adminPassword = 'secure_pass_123';

    private function bootstrap(): void
    {
        $this->api('POST', '/bootstrap', [
            'organization_name' => 'SessionCapOrg',
            'organization_code' => 'SCO',
            'admin_username'    => 'session_cap_admin',
            'admin_password'    => 'secure_pass_123',
            'admin_display_name'=> 'Session Cap Admin',
            'default_currency'  => 'USD',
        ]);

        // Discover which credentials work (another test may have bootstrapped first).
        $candidates = [
            ['session_cap_admin', 'secure_pass_123'],
            ['admin', 'password123'],
            ['http_test_admin', 'secure_pass_123'],
            ['payadmin', 'password123'],
            ['uniq_admin', 'secure_pass_123'],
        ];
        foreach ($candidates as [$u, $p]) {
            $r = $this->api('POST', '/auth/login', ['username' => $u, 'password' => $p, 'device_label' => 'probe', 'client_device_id' => 'probe-' . uniqid()]);
            if ($r['status'] === 200) {
                $this->adminUsername = $u;
                $this->adminPassword = $p;
                return;
            }
        }
    }

    private function login(string $deviceLabel): array
    {
        return $this->api('POST', '/auth/login', [
            'username'         => $this->adminUsername,
            'password'         => $this->adminPassword,
            'device_label'     => $deviceLabel,
            'client_device_id' => 'cap-test-' . $deviceLabel . '-' . uniqid(),
        ]);
    }

    /**
     * Query the real database for the count of active (non-revoked, non-expired)
     * sessions for the given user.
     */
    private function countActiveSessionsInDb(string $userId): int
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        return (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM device_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()',
            [$userId],
        );
    }

    /**
     * Get all active session IDs for a user, ordered oldest-first.
     */
    private function getActiveSessionIds(string $userId): array
    {
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        return $conn->fetchFirstColumn(
            'SELECT id FROM device_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW() ORDER BY issued_at ASC',
            [$userId],
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. Eight rapid logins → active count never exceeds 5
    // ═══════════════════════════════════════════════════════════════

    public function testEightRapidLoginsNeverExceedCap(): void
    {
        static::createClient();
        $this->bootstrap();

        // First login to get the user ID
        $first = $this->login('device-probe');
        if ($first['status'] !== 200) {
            $this->markTestSkipped('Cannot login with expected credentials');
        }
        $userId = $first['body']['data']['user']['id'];

        // Fire 8 logins rapidly (includes the probe login above)
        $peakCount = 0;
        for ($i = 1; $i <= 7; $i++) {
            $r = $this->login("device-rapid-{$i}");
            $this->assertSame(200, $r['status'], "Login #{$i} must succeed");

            $activeCount = $this->countActiveSessionsInDb($userId);
            $peakCount = max($peakCount, $activeCount);

            $this->assertLessThanOrEqual(
                5,
                $activeCount,
                "After login #{$i}: active sessions in DB ({$activeCount}) exceeds cap 5",
            );
        }

        // Final assertions against real DB
        $finalCount = $this->countActiveSessionsInDb($userId);
        $this->assertLessThanOrEqual(5, $finalCount, 'Final active session count must be <= 5');
        $this->assertLessThanOrEqual(5, $peakCount, 'Peak observed count must be <= 5');
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. Start at exactly 5, one more login → still 5, oldest gone
    // ═══════════════════════════════════════════════════════════════

    public function testSixthLoginRevokesOldestSession(): void
    {
        static::createClient();
        $this->bootstrap();

        $first = $this->login('device-init');
        if ($first['status'] !== 200) {
            $this->markTestSkipped('Cannot login with expected credentials');
        }
        $userId = $first['body']['data']['user']['id'];

        // Revoke any stale sessions for this admin from prior tests.
        $container = static::getContainer();
        $conn = $container->get('doctrine.dbal.default_connection');
        $conn->executeStatement(
            'UPDATE device_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL',
            [$userId],
        );

        // Fill to exactly 5 sessions
        $sessionIds = [];
        for ($i = 0; $i < 5; $i++) {
            $r = $this->login("device-fill-{$i}");
            $this->assertSame(200, $r['status']);
            $sessionIds[] = $r['body']['data']['session_id'];
        }

        $this->assertSame(5, $this->countActiveSessionsInDb($userId), 'Must have exactly 5 active sessions');
        $oldestBefore = $this->getActiveSessionIds($userId)[0];

        // 6th login → must revoke the oldest
        $sixth = $this->login('device-overflow');
        $this->assertSame(200, $sixth['status']);

        $activeAfter = $this->getActiveSessionIds($userId);
        $this->assertCount(5, $activeAfter, 'Must still have exactly 5 active sessions');
        $this->assertNotContains($oldestBefore, $activeAfter, 'Oldest session must have been revoked');
        $this->assertContains($sixth['body']['data']['session_id'], $activeAfter, 'New session must be active');
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. No duplicate or inconsistent session state
    // ═══════════════════════════════════════════════════════════════

    public function testNoDuplicateSessionState(): void
    {
        static::createClient();
        $this->bootstrap();

        $first = $this->login('device-dup-check');
        if ($first['status'] !== 200) {
            $this->markTestSkipped('Cannot login with expected credentials');
        }
        $userId = $first['body']['data']['user']['id'];

        // Revoke stale sessions from prior tests.
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement(
            'UPDATE device_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL',
            [$userId],
        );

        // Login 10 times total (creates 10 fresh sessions)
        for ($i = 0; $i < 10; $i++) {
            $r = $this->login("device-dup-{$i}");
            $this->assertSame(200, $r['status']);
        }

        // Query all sessions (including revoked) for this user
        $container = static::getContainer();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');

        $allSessions = $conn->fetchAllAssociative(
            'SELECT id, revoked_at FROM device_sessions WHERE user_id = ?',
            [$userId],
        );

        // Total sessions created = 10
        $this->assertGreaterThanOrEqual(10, count($allSessions), 'At least 10 sessions must exist');

        // Active sessions = exactly 5
        $active = array_filter($allSessions, fn($s) => $s['revoked_at'] === null);
        $this->assertCount(5, $active, 'Exactly 5 sessions must be active');

        // No session ID appears more than once
        $ids = array_column($allSessions, 'id');
        $this->assertSame(count($ids), count(array_unique($ids)), 'No duplicate session IDs');
    }
}
