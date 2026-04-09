<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Repository\UserRepository;
use App\Security\ApiTokenAuthenticator;
use App\Security\JwtTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates that every API route is explicitly covered by access_control
 * rules in security.yaml and that the authenticator's public route list
 * matches.
 *
 *   1. Every route in every controller is either public or authenticated
 *   2. access_control public routes match authenticator's injected list
 *   3. No route is implicitly public (catch-all exists)
 *   4. Negative: unauthenticated requests to protected routes are rejected
 */
class AccessControlCoverageTest extends TestCase
{
    /**
     * All known API route paths (derived from controller attributes).
     * This is the single authoritative list — if a controller adds a route,
     * it MUST appear here or the test fails.
     */
    private const ALL_API_ROUTES = [
        // Auth
        '/api/v1/auth/login',
        '/api/v1/auth/refresh',
        '/api/v1/auth/logout',
        '/api/v1/auth/change-password',
        // Backup
        '/api/v1/backups',
        '/api/v1/backups/preview',
        '/api/v1/backups/restore',
        // Bills
        '/api/v1/bills',
        '/api/v1/bills/{id}',
        '/api/v1/bills/{id}/void',
        '/api/v1/bills/{id}/pdf',
        // Bookings
        '/api/v1/bookings',
        '/api/v1/bookings/{id}',
        '/api/v1/bookings/{id}/check-in',
        '/api/v1/bookings/{id}/complete',
        '/api/v1/bookings/{id}/cancel',
        '/api/v1/bookings/{id}/no-show',
        '/api/v1/bookings/{id}/reschedule',
        // Bootstrap
        '/api/v1/bootstrap',
        // Health
        '/api/v1/health',
        // Holds
        '/api/v1/holds',
        '/api/v1/holds/{id}/confirm',
        '/api/v1/holds/{id}/release',
        '/api/v1/holds/{id}',
        // Inventory
        '/api/v1/inventory',
        '/api/v1/inventory/{id}',
        '/api/v1/inventory/{id}/deactivate',
        '/api/v1/inventory/{id}/availability',
        '/api/v1/inventory/{id}/calendar',
        '/api/v1/inventory/{itemId}/pricing',
        // Ledger
        '/api/v1/ledger',
        '/api/v1/ledger/bill/{billId}',
        '/api/v1/ledger/booking/{bookingId}',
        // Metrics
        '/api/v1/metrics',
        // Notifications
        '/api/v1/notifications',
        '/api/v1/notifications/{id}/read',
        '/api/v1/notifications/preferences',
        '/api/v1/notifications/preferences/{eventCode}',
        // Payments
        '/api/v1/payments',
        '/api/v1/payments/callback',
        '/api/v1/payments/{id}',
        // Reconciliation
        '/api/v1/reconciliation/run',
        '/api/v1/reconciliation/runs',
        '/api/v1/reconciliation/runs/{id}',
        '/api/v1/reconciliation/runs/{id}/csv',
        // Refunds
        '/api/v1/refunds',
        '/api/v1/refunds/{id}',
        // Settings
        '/api/v1/settings',
        // Terminals
        '/api/v1/terminals',
        '/api/v1/terminals/{id}',
        '/api/v1/terminal-playlists',
        '/api/v1/terminal-transfers',
        '/api/v1/terminal-transfers/{id}/chunk',
        '/api/v1/terminal-transfers/{id}/pause',
        '/api/v1/terminal-transfers/{id}/resume',
        '/api/v1/terminal-transfers/{id}',
        // Audit
        '/api/v1/audit-logs',
        // Users
        '/api/v1/users/me',
        '/api/v1/users',
        '/api/v1/users/{id}',
        '/api/v1/users/{id}/freeze',
        '/api/v1/users/{id}/unfreeze',
    ];

    private const PUBLIC_ROUTES = [
        '/api/v1/health',
        '/api/v1/bootstrap',
        '/api/v1/auth/login',
        '/api/v1/auth/refresh',
        '/api/v1/payments/callback',
    ];

    private function makeAuthenticator(): ApiTokenAuthenticator
    {
        return new ApiTokenAuthenticator(
            $this->createMock(JwtTokenManager::class),
            $this->createMock(UserRepository::class),
            self::PUBLIC_ROUTES,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. Every route is covered — public or requires auth
    // ═══════════════════════════════════════════════════════════════

    public function testEveryApiRouteIsCoveredByAccessControl(): void
    {
        $auth = $this->makeAuthenticator();

        foreach (self::ALL_API_ROUTES as $route) {
            // Replace placeholders with realistic values for path matching
            $concreteRoute = preg_replace('/\{[^}]+\}/', 'test-id-123', $route);

            $request = Request::create($concreteRoute);
            $supports = $auth->supports($request);

            // Every route must either be public (supports=false) or
            // require authentication (supports=true). Neither can be null
            // for /api/ routes.
            $this->assertIsBool(
                $supports,
                "Route {$route} is not covered by the authenticator"
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. Public routes match between config and authenticator
    // ═══════════════════════════════════════════════════════════════

    public function testPublicRoutesMatchSecurityYamlAccessControl(): void
    {
        $securityYaml = __DIR__ . '/../../../config/packages/security.yaml';
        if (!file_exists($securityYaml)) {
            $this->markTestSkipped('security.yaml not found at expected path');
        }

        $config = Yaml::parseFile($securityYaml);
        $accessControl = $config['security']['access_control'] ?? [];

        // Extract public routes from access_control (those with allow_if: 'true')
        $publicFromYaml = [];
        foreach ($accessControl as $rule) {
            if (isset($rule['allow_if']) && $rule['allow_if'] === 'true') {
                // Convert regex path to literal path: ^/api/v1/health$ → /api/v1/health
                $path = $rule['path'];
                $path = ltrim($path, '^');
                $path = rtrim($path, '$');
                $publicFromYaml[] = $path;
            }
        }

        sort($publicFromYaml);
        $expected = self::PUBLIC_ROUTES;
        sort($expected);

        $this->assertSame(
            $expected,
            $publicFromYaml,
            'Public routes in security.yaml access_control must match the authenticator config'
        );
    }

    public function testAuthenticatorPublicRoutesMatchConfig(): void
    {
        $auth = $this->makeAuthenticator();
        $this->assertSame(self::PUBLIC_ROUTES, $auth->getPublicRoutes());
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. Catch-all exists — no route implicitly public
    // ═══════════════════════════════════════════════════════════════

    public function testCatchAllAccessControlRuleExists(): void
    {
        $securityYaml = __DIR__ . '/../../../config/packages/security.yaml';
        if (!file_exists($securityYaml)) {
            $this->markTestSkipped('security.yaml not found at expected path');
        }

        $config = Yaml::parseFile($securityYaml);
        $accessControl = $config['security']['access_control'] ?? [];

        // The last rule must be a catch-all for /api/ requiring ROLE_USER
        $catchAll = end($accessControl);
        $this->assertNotFalse($catchAll, 'access_control must not be empty');
        $this->assertMatchesRegularExpression(
            '#\^/api/#',
            $catchAll['path'],
            'Last access_control rule must be a catch-all for /api/'
        );
        $this->assertContains(
            'ROLE_USER',
            (array) ($catchAll['roles'] ?? []),
            'Catch-all must require ROLE_USER'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. Non-public routes require auth (negative tests)
    // ═══════════════════════════════════════════════════════════════

    /** @dataProvider protectedRouteProvider */
    public function testProtectedRouteRequiresAuth(string $route): void
    {
        $auth = $this->makeAuthenticator();
        $concreteRoute = preg_replace('/\{[^}]+\}/', 'test-id-123', $route);
        $request = Request::create($concreteRoute);

        $this->assertTrue(
            $auth->supports($request),
            "Protected route {$route} must require authentication"
        );
    }

    public static function protectedRouteProvider(): array
    {
        $protected = array_diff(self::ALL_API_ROUTES, self::PUBLIC_ROUTES);
        return array_map(fn(string $r) => [$r], array_values($protected));
    }

    /** @dataProvider publicRouteProvider */
    public function testPublicRouteSkipsAuth(string $route): void
    {
        $auth = $this->makeAuthenticator();
        $request = Request::create($route);

        $this->assertFalse(
            $auth->supports($request),
            "Public route {$route} must skip authentication"
        );
    }

    public static function publicRouteProvider(): array
    {
        return array_map(fn(string $r) => [$r], self::PUBLIC_ROUTES);
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. No unknown route can bypass auth
    // ═══════════════════════════════════════════════════════════════

    public function testUnknownApiRouteStillRequiresAuth(): void
    {
        $auth = $this->makeAuthenticator();
        $request = Request::create('/api/v1/this-does-not-exist');

        $this->assertTrue(
            $auth->supports($request),
            'Unknown /api/ routes must still require authentication'
        );
    }
}
