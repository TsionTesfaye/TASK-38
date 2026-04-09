<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use App\Security\JwtTokenManager;
use App\Service\AuditService;
use App\Service\AuthService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Validates that username global uniqueness is the correct design for
 * this system's authentication model.
 *
 * DESIGN DECISION (documented):
 *
 *   Username is GLOBALLY unique (not scoped to organization_id).
 *
 *   Reason: The login flow (`POST /api/v1/auth/login`) accepts only
 *   `username` and `password` — there is no organization identifier
 *   in the login request. The system resolves the user and their
 *   organization from the username alone. Therefore:
 *
 *   - Global uniqueness is REQUIRED for unambiguous user resolution
 *   - If per-org uniqueness were used, login would need an org
 *     identifier (org_code, subdomain, etc.) — that's a feature change
 *   - The bootstrap flow creates the first org+admin pair with a
 *     globally unique username — this is the system's entry point
 *
 *   If the system evolves to support org-scoped login (e.g., via
 *   subdomain routing or an org_code field in the login payload),
 *   the uniqueness constraint should change to (organization_id, username)
 *   via a migration and corresponding auth lookup changes.
 */
class UsernameUniquenessTest extends TestCase
{
    /**
     * Prove: findByUsername is the ONLY user-resolution path for login,
     * and it resolves exactly one user without org context.
     */
    public function testAuthenticateResolvesUserByUsernameAlone(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('isActive')->willReturn(true);
        $user->method('isFrozen')->willReturn(false);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getPasswordHash')->willReturn(password_hash('pass', PASSWORD_BCRYPT));
        $user->method('getRole')->willReturn(UserRole::TENANT);
        $user->method('getUsername')->willReturn('unique_user');

        $userRepo = $this->createMock(UserRepository::class);

        // findByUsername is called with username only — no org parameter
        $userRepo->expects($this->once())
            ->method('findByUsername')
            ->with('unique_user')
            ->willReturn($user);

        $deviceSessionRepo = $this->createMock(\App\Repository\DeviceSessionRepository::class);
        $deviceSessionRepo->method('revokeExcessByUserId')->willReturn(0);

        $settingsRepo = $this->createMock(\App\Repository\SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

        $jwtManager = $this->createMock(JwtTokenManager::class);
        $jwtManager->method('createRefreshToken')->willReturn('rt');
        $jwtManager->method('hashRefreshToken')->willReturn('h');
        $jwtManager->method('getRefreshTokenTtl')->willReturn(86400);
        $jwtManager->method('createAccessToken')->willReturn('at');
        $jwtManager->method('getAccessTokenTtl')->willReturn(900);

        $authService = new AuthService(
            $userRepo,
            $deviceSessionRepo,
            $settingsRepo,
            $jwtManager,
            $this->createMock(AuditService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $result = $authService->authenticate('unique_user', 'pass', 'dev', 'cli');

        // Login succeeded — user resolved without org context
        $this->assertArrayHasKey('access_token', $result);
        $this->assertSame($user, $result['user']);
    }

    /**
     * Prove: the User entity enforces global uniqueness via ORM mapping.
     */
    public function testUserEntityHasGlobalUniqueConstraintOnUsername(): void
    {
        $ref = new \ReflectionClass(User::class);
        $prop = $ref->getProperty('username');

        $attrs = $prop->getAttributes(\Doctrine\ORM\Mapping\Column::class);
        $this->assertCount(1, $attrs, 'username must have exactly one ORM\Column attribute');

        $args = $attrs[0]->getArguments();
        $this->assertTrue(
            $args['unique'] ?? false,
            'username ORM\Column must have unique: true for global uniqueness'
        );
    }

    /**
     * Prove: findByUsername does not accept an org parameter.
     */
    public function testFindByUsernameSignatureHasNoOrgParameter(): void
    {
        $ref = new \ReflectionMethod(UserRepository::class, 'findByUsername');
        $params = $ref->getParameters();

        $this->assertCount(1, $params, 'findByUsername must accept exactly one parameter (username)');
        $this->assertSame('username', $params[0]->getName());
    }

    /**
     * Prove: login payload accepted by AuthController has NO org field.
     * The login action calls AuthService::authenticate(username, password, ...),
     * which calls findByUsername(username) — no org context at any point.
     */
    public function testAuthenticateMethodSignatureHasNoOrgParameter(): void
    {
        $ref = new \ReflectionMethod(AuthService::class, 'authenticate');
        $params = $ref->getParameters();

        // authenticate(string $username, string $password, string $deviceLabel, string $clientDeviceId)
        $this->assertSame('username', $params[0]->getName());
        $this->assertSame('password', $params[1]->getName());

        // No parameter named 'org', 'organization', 'orgId', etc.
        $paramNames = array_map(fn($p) => $p->getName(), $params);
        foreach ($paramNames as $name) {
            $this->assertStringNotContainsString(
                'org',
                strtolower($name),
                "authenticate() must not accept an org parameter (found: {$name})",
            );
        }
    }

    /**
     * Prove: UserService::createUser() rejects duplicate usernames globally
     * (not per-org), confirming the identity model is consistent.
     */
    public function testCreateUserRejectsDuplicateUsernameGlobally(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $existingUser = $this->createMock(User::class);
        $existingUser->method('getOrganizationId')->willReturn('org-2'); // different org!

        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn('admin-1');
        $admin->method('getRole')->willReturn(UserRole::ADMINISTRATOR);
        $admin->method('getOrganization')->willReturn($org);
        $admin->method('getOrganizationId')->willReturn('org-1');
        $admin->method('getUsername')->willReturn('admin');

        $userRepo = $this->createMock(UserRepository::class);
        // findByUsername returns a user from org-2 → duplicate detected globally
        $userRepo->method('findByUsername')->with('shared_name')->willReturn($existingUser);

        $service = new \App\Service\UserService(
            $userRepo,
            $this->createMock(\App\Repository\DeviceSessionRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(\App\Security\OrganizationScope::class),
            $this->createMock(\App\Security\RbacEnforcer::class),
            $this->createMock(AuditService::class),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Username already exists');

        // Admin in org-1 tries to create user with username that exists in org-2
        // → must fail, proving global uniqueness enforcement
        $service->createUser($admin, 'shared_name', 'password', 'Display', 'tenant');
    }
}
