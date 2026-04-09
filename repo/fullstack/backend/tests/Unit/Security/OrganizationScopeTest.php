<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Organization;
use App\Entity\User;
use App\Exception\OrganizationScopeMismatchException;
use App\Security\OrganizationScope;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrganizationScopeTest extends TestCase
{
    private OrganizationScope $scope;

    protected function setUp(): void
    {
        $this->scope = new OrganizationScope();
    }

    private function makeUser(string $orgId): User
    {
        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        $user->method('getOrganizationId')->willReturn($orgId);

        return $user;
    }

    public function testGetOrganizationIdReturnsUsersOrgId(): void
    {
        $user = $this->makeUser('org-abc');

        $this->assertSame('org-abc', $this->scope->getOrganizationId($user));
    }

    public function testAssertSameOrganizationPassesWhenSameOrg(): void
    {
        $user = $this->makeUser('org-abc');

        $this->scope->assertSameOrganization($user, 'org-abc');
        $this->addToAssertionCount(1); // no exception = pass
    }

    public function testAssertSameOrganizationThrowsWhenDifferentOrg(): void
    {
        $user = $this->makeUser('org-abc');

        $this->expectException(OrganizationScopeMismatchException::class);
        $this->scope->assertSameOrganization($user, 'org-xyz');
    }

    public function testScopeQueryReturnsOrgIdFilter(): void
    {
        $user = $this->makeUser('org-abc');

        $this->assertSame(['organization_id' => 'org-abc'], $this->scope->scopeQuery($user));
    }

    // ─── Cross-tenant isolation ────────────────────────────────────────────

    public function testCrossTenantAccessIsBlocked(): void
    {
        $userOrgA = $this->makeUser('org-a');
        $resourceOrgB = 'org-b';

        $this->expectException(OrganizationScopeMismatchException::class);
        $this->scope->assertSameOrganization($userOrgA, $resourceOrgB);
    }

    public function testUserFromOrgBCannotAccessOrgAResource(): void
    {
        $userOrgB = $this->makeUser('org-b');

        $this->expectException(OrganizationScopeMismatchException::class);
        $this->scope->assertSameOrganization($userOrgB, 'org-a');
    }
}
