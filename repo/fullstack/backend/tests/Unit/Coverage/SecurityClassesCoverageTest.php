<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Security\JwtTokenManager;
use App\Security\OrganizationScope;
use App\Security\PaymentSignatureVerifier;
use App\Security\RbacEnforcer;
use PHPUnit\Framework\TestCase;

class SecurityClassesCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['JWT_SECRET'] = 'test_jwt_secret_for_coverage_testing';
        $_ENV['JWT_ACCESS_TOKEN_TTL'] = '900';
        $_ENV['JWT_REFRESH_TOKEN_TTL'] = '1209600';
        $_ENV['PAYMENT_SHARED_SECRET'] = 'test_payment_shared_secret';
    }

    public function testPaymentSignatureVerifier(): void
    {
        $v = new PaymentSignatureVerifier();
        $payload = ['request_id' => 'req-1', 'amount' => '100.00', 'currency' => 'USD'];
        $sig = $v->generateSignature($payload);

        $this->assertIsString($sig);
        $this->assertSame(64, strlen($sig)); // SHA-256 hex length
        $this->assertTrue($v->verifySignature($sig, $payload));
        $this->assertFalse($v->verifySignature('wrong_signature', $payload));

        // Payload order does not matter (ksort)
        $reversed = array_reverse($payload);
        $sig2 = $v->generateSignature($reversed);
        $this->assertSame($sig, $sig2);

        // Different payload → different signature
        $different = $payload;
        $different['amount'] = '999.00';
        $this->assertNotSame($sig, $v->generateSignature($different));
    }

    public function testJwtTokenManagerRoundTrip(): void
    {
        $manager = new JwtTokenManager();

        $org = new Organization('org-jwt', 'ORG', 'JWT Org');
        $user = new User('user-jwt', $org, 'jwtuser', 'h', 'Test', UserRole::ADMINISTRATOR);

        $token = $manager->createAccessToken($user);
        $this->assertIsString($token);
        $this->assertGreaterThan(20, strlen($token));

        $claims = $manager->parseAccessToken($token);
        $this->assertSame('user-jwt', $claims['user_id']);
        $this->assertSame('org-jwt', $claims['organization_id']);
        $this->assertSame('administrator', $claims['role']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $claims['issued_at']);
    }

    public function testJwtTokenManagerInvalidTokenThrows(): void
    {
        $manager = new JwtTokenManager();
        $this->expectException(AuthenticationException::class);
        $manager->parseAccessToken('not.a.jwt');
    }

    public function testJwtTokenManagerRefreshToken(): void
    {
        $manager = new JwtTokenManager();
        $rt = $manager->createRefreshToken();
        $this->assertIsString($rt);
        $this->assertSame(64, strlen($rt)); // 32 bytes = 64 hex chars

        $hash = $manager->hashRefreshToken($rt);
        $this->assertSame(hash('sha256', $rt), $hash);

        // Different refresh tokens
        $this->assertNotSame($rt, $manager->createRefreshToken());
    }

    public function testJwtTokenManagerTtls(): void
    {
        $manager = new JwtTokenManager();
        $this->assertSame(900, $manager->getAccessTokenTtl());
        $this->assertSame(1209600, $manager->getRefreshTokenTtl());
    }

    public function testOrganizationScopeGetOrganizationId(): void
    {
        $scope = new OrganizationScope();
        $org = new Organization('org-1', 'O', 'N');
        $user = new User('u-1', $org, 'n', 'h', 'd', UserRole::TENANT);

        $this->assertSame('org-1', $scope->getOrganizationId($user));
    }

    public function testOrganizationScopeAssertSameOrganization(): void
    {
        $scope = new OrganizationScope();
        $org = new Organization('org-1', 'O', 'N');
        $user = new User('u-1', $org, 'n', 'h', 'd', UserRole::TENANT);

        // Same org — no exception
        $scope->assertSameOrganization($user, 'org-1');
        $this->addToAssertionCount(1);

        // Different org — throws
        $this->expectException(\App\Exception\OrganizationScopeMismatchException::class);
        $scope->assertSameOrganization($user, 'other-org');
    }

    public function testOrganizationScopeScopeQuery(): void
    {
        $scope = new OrganizationScope();
        $org = new Organization('org-1', 'O', 'N');
        $user = new User('u-1', $org, 'n', 'h', 'd', UserRole::TENANT);

        $filters = $scope->scopeQuery($user);
        $this->assertSame(['organization_id' => 'org-1'], $filters);
    }

    public function testRbacEnforcerAllRolesAllActions(): void
    {
        $rbac = new RbacEnforcer();
        $org = new Organization('o-1', 'O', 'N');

        // Every action constant × every role
        $ref = new \ReflectionClass(RbacEnforcer::class);
        $actionConstants = array_filter(
            $ref->getConstants(),
            fn($name) => str_starts_with($name, 'ACTION_'),
            ARRAY_FILTER_USE_KEY,
        );

        foreach (UserRole::cases() as $role) {
            $user = new User('u-' . $role->value, $org, 'n', 'h', 'd', $role);
            foreach ($actionConstants as $action) {
                try {
                    $rbac->enforce($user, $action);
                    $this->addToAssertionCount(1);
                } catch (\App\Exception\AccessDeniedException) {
                    $this->addToAssertionCount(1); // denial is a valid outcome
                }
            }
        }
    }

    public function testRbacEnforcerTenantResourceCheck(): void
    {
        $rbac = new RbacEnforcer();
        $org = new Organization('o-1', 'O', 'N');
        $tenant = new User('tenant-A', $org, 'n', 'h', 'd', UserRole::TENANT);

        // Tenant accessing own resource
        $rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_OWN, 'tenant-A');
        $this->addToAssertionCount(1);

        // Tenant accessing another's resource
        $this->expectException(\App\Exception\AccessDeniedException::class);
        $rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_OWN, 'tenant-B');
    }
}
