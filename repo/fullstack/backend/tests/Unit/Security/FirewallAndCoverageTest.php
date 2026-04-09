<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Repository\UserRepository;
use App\Security\ApiTokenAuthenticator;
use App\Security\JwtTokenManager;
use App\Security\RbacEnforcer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Tests the consolidated auth path (ApiTokenAuthenticator) and RBAC matrix:
 *   1. Public routes skip auth (supports() returns false)
 *   2. Protected routes require auth (supports() returns true)
 *   3. Invalid/missing tokens fail authentication
 *   4. Password-invalidated tokens rejected
 *   5. Privileged-route RBAC matrix
 *   6. Frozen/inactive users rejected
 */
class FirewallAndCoverageTest extends TestCase
{
    private const PUBLIC_ROUTES = [
        '/api/v1/health',
        '/api/v1/bootstrap',
        '/api/v1/auth/login',
        '/api/v1/auth/refresh',
        '/api/v1/payments/callback',
    ];

    private function makeAuthenticator(
        ?User $userToReturn = null,
        ?\DateTimeImmutable $tokenIssuedAt = null,
    ): ApiTokenAuthenticator {
        $jwtManager = $this->createMock(JwtTokenManager::class);
        $userRepo = $this->createMock(UserRepository::class);

        if ($userToReturn !== null) {
            $jwtManager->method('parseAccessToken')->willReturn([
                'user_id' => $userToReturn->getId(),
                'organization_id' => 'org-1',
                'role' => $userToReturn->getRole()->value,
                'issued_at' => $tokenIssuedAt ?? new \DateTimeImmutable(),
            ]);
            $userRepo->method('find')->willReturn($userToReturn);
        } else {
            $jwtManager->method('parseAccessToken')->willThrowException(
                new \App\Exception\AuthenticationException('Invalid token')
            );
        }

        return new ApiTokenAuthenticator($jwtManager, $userRepo, self::PUBLIC_ROUTES);
    }

    private function makeActiveUser(?\DateTimeImmutable $passwordChangedAt = null): User&MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('getRole')->willReturn(UserRole::TENANT);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('isActive')->willReturn(true);
        $user->method('isFrozen')->willReturn(false);
        $user->method('getPasswordChangedAt')->willReturn($passwordChangedAt);
        $user->method('getUserIdentifier')->willReturn('user-1');
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        return $user;
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. Public routes skip auth
    // ═══════════════════════════════════════════════════════════════

    public function testPublicRoutesReturnFalseFromSupports(): void
    {
        $auth = $this->makeAuthenticator();
        $publicRoutes = [
            '/api/v1/health', '/api/v1/bootstrap', '/api/v1/auth/login',
            '/api/v1/auth/refresh', '/api/v1/payments/callback',
        ];

        foreach ($publicRoutes as $route) {
            $this->assertFalse($auth->supports(Request::create($route)), "Public route {$route} must skip auth");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. Protected routes require auth
    // ═══════════════════════════════════════════════════════════════

    public function testProtectedRoutesReturnTrueFromSupports(): void
    {
        $auth = $this->makeAuthenticator();
        $this->assertTrue($auth->supports(Request::create('/api/v1/bookings')));
        $this->assertTrue($auth->supports(Request::create('/api/v1/users')));
        $this->assertTrue($auth->supports(Request::create('/api/v1/settings')));
    }

    public function testNonApiRoutesReturnFalseFromSupports(): void
    {
        $auth = $this->makeAuthenticator();
        $this->assertFalse($auth->supports(Request::create('/login')));
        $this->assertFalse($auth->supports(Request::create('/')));
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. Token validation through authenticate()
    // ═══════════════════════════════════════════════════════════════

    public function testMissingBearerTokenThrows(): void
    {
        $auth = $this->makeAuthenticator();
        $request = Request::create('/api/v1/bookings');
        // No Authorization header

        $this->expectException(AuthenticationException::class);
        $auth->authenticate($request);
    }

    public function testInvalidTokenThrows(): void
    {
        $auth = $this->makeAuthenticator(); // parseAccessToken throws
        $request = Request::create('/api/v1/bookings');
        $request->headers->set('Authorization', 'Bearer bad.jwt');

        $this->expectException(AuthenticationException::class);
        $auth->authenticate($request);
    }

    public function testValidTokenReturnsPassport(): void
    {
        $user = $this->makeActiveUser();
        $auth = $this->makeAuthenticator($user);
        $request = Request::create('/api/v1/bookings');
        $request->headers->set('Authorization', 'Bearer valid.jwt');

        $passport = $auth->authenticate($request);
        $this->assertNotNull($passport);
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. Password-invalidated tokens
    // ═══════════════════════════════════════════════════════════════

    public function testTokenIssuedBeforePasswordChangeRejected(): void
    {
        $user = $this->makeActiveUser(new \DateTimeImmutable('-1 hour'));
        $auth = $this->makeAuthenticator($user, new \DateTimeImmutable('-2 hours'));
        $request = Request::create('/api/v1/bookings');
        $request->headers->set('Authorization', 'Bearer stale.jwt');

        $passport = $auth->authenticate($request);
        $this->expectException(AuthenticationException::class);
        $passport->getBadge(UserBadge::class)->getUser();
    }

    public function testTokenIssuedAfterPasswordChangeAccepted(): void
    {
        $user = $this->makeActiveUser(new \DateTimeImmutable('-2 hours'));
        $auth = $this->makeAuthenticator($user, new \DateTimeImmutable('-1 hour'));
        $request = Request::create('/api/v1/bookings');
        $request->headers->set('Authorization', 'Bearer fresh.jwt');

        $passport = $auth->authenticate($request);
        $this->assertNotNull($passport);
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. Privileged-route RBAC matrix
    // ═══════════════════════════════════════════════════════════════

    private function makeRbacUser(UserRole $role): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('u-1');
        $user->method('getRole')->willReturn($role);
        return $user;
    }

    /** @dataProvider privilegedRouteProvider */
    public function testPrivilegedRouteMatrix(string $action, UserRole $role, bool $allowed): void
    {
        $rbac = new RbacEnforcer();
        $user = $this->makeRbacUser($role);

        if ($allowed) {
            $rbac->enforce($user, $action);
            $this->addToAssertionCount(1);
        } else {
            $this->expectException(AccessDeniedException::class);
            $rbac->enforce($user, $action);
        }
    }

    public static function privilegedRouteProvider(): array
    {
        return [
            ['MANAGE_BACKUPS', UserRole::ADMINISTRATOR, true],
            ['MANAGE_BACKUPS', UserRole::PROPERTY_MANAGER, false],
            ['MANAGE_BACKUPS', UserRole::TENANT, false],
            ['MANAGE_BACKUPS', UserRole::FINANCE_CLERK, false],
            ['VIEW_AUDIT', UserRole::ADMINISTRATOR, true],
            ['VIEW_AUDIT', UserRole::PROPERTY_MANAGER, false],
            ['VIEW_AUDIT', UserRole::FINANCE_CLERK, false],
            ['MANAGE_TERMINALS', UserRole::ADMINISTRATOR, true],
            ['MANAGE_TERMINALS', UserRole::PROPERTY_MANAGER, true],
            ['MANAGE_TERMINALS', UserRole::TENANT, false],
            ['MANAGE_TERMINALS', UserRole::FINANCE_CLERK, false],
            ['VIEW_SETTINGS', UserRole::ADMINISTRATOR, true],
            ['VIEW_SETTINGS', UserRole::PROPERTY_MANAGER, true],
            ['VIEW_SETTINGS', UserRole::FINANCE_CLERK, true],
            ['VIEW_SETTINGS', UserRole::TENANT, false],
            ['MANAGE_SETTINGS', UserRole::ADMINISTRATOR, true],
            ['MANAGE_SETTINGS', UserRole::PROPERTY_MANAGER, false],
            ['PROCESS_REFUND', UserRole::ADMINISTRATOR, true],
            ['PROCESS_REFUND', UserRole::PROPERTY_MANAGER, true],
            ['PROCESS_REFUND', UserRole::FINANCE_CLERK, true],
            ['PROCESS_REFUND', UserRole::TENANT, false],
            ['MARK_NOSHOW', UserRole::ADMINISTRATOR, true],
            ['MARK_NOSHOW', UserRole::PROPERTY_MANAGER, true],
            ['MARK_NOSHOW', UserRole::TENANT, false],
            ['MARK_NOSHOW', UserRole::FINANCE_CLERK, false],
            ['CHECK_IN', UserRole::ADMINISTRATOR, true],
            ['CHECK_IN', UserRole::PROPERTY_MANAGER, true],
            ['CHECK_IN', UserRole::TENANT, false],
            ['CHECK_IN', UserRole::FINANCE_CLERK, false],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // 6. Frozen / inactive user rejected
    // ═══════════════════════════════════════════════════════════════

    public function testFrozenUserRejected(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('frozen-1');
        $user->method('isActive')->willReturn(true);
        $user->method('isFrozen')->willReturn(true);
        $user->method('getRole')->willReturn(UserRole::TENANT);

        $auth = $this->makeAuthenticator($user);
        $request = Request::create('/api/v1/bookings');
        $request->headers->set('Authorization', 'Bearer frozen.jwt');

        $passport = $auth->authenticate($request);
        // The user-loader callback runs lazily; resolve the badge to trigger it.
        $this->expectException(AuthenticationException::class);
        $passport->getBadge(UserBadge::class)->getUser();
    }

    public function testInactiveUserRejected(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('inactive-1');
        $user->method('isActive')->willReturn(false);
        $user->method('isFrozen')->willReturn(false);
        $user->method('getRole')->willReturn(UserRole::TENANT);

        $auth = $this->makeAuthenticator($user);
        $request = Request::create('/api/v1/bookings');
        $request->headers->set('Authorization', 'Bearer inactive.jwt');

        $passport = $auth->authenticate($request);
        $this->expectException(AuthenticationException::class);
        $passport->getBadge(UserBadge::class)->getUser();
    }
}
