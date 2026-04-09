<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Security\RbacEnforcer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies RBAC enforcement for terminal and audit endpoints.
 *
 * Terminal read/list/getTransfer/listPlaylists: only ADMINISTRATOR and PROPERTY_MANAGER.
 * Audit log list: only ADMINISTRATOR.
 */
class EndpointRbacTest extends TestCase
{
    private RbacEnforcer $rbac;

    protected function setUp(): void
    {
        $this->rbac = new RbacEnforcer();
    }

    private function makeUser(UserRole $role, string $id = 'user-1'): User
    {
        /** @var Organization&MockObject $org */
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRole')->willReturn($role);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');

        return $user;
    }

    // ─── Terminal: MANAGE_TERMINALS ────────────────────────────────────────

    public function testAdministratorCanListTerminals(): void
    {
        $this->rbac->enforce($this->makeUser(UserRole::ADMINISTRATOR), RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $this->addToAssertionCount(1);
    }

    public function testPropertyManagerCanListTerminals(): void
    {
        $this->rbac->enforce($this->makeUser(UserRole::PROPERTY_MANAGER), RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $this->addToAssertionCount(1);
    }

    public function testTenantCannotListTerminals(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::TENANT), RbacEnforcer::ACTION_MANAGE_TERMINALS);
    }

    public function testFinanceClerkCannotListTerminals(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::FINANCE_CLERK), RbacEnforcer::ACTION_MANAGE_TERMINALS);
    }

    public function testTenantCannotGetTerminal(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::TENANT), RbacEnforcer::ACTION_MANAGE_TERMINALS);
    }

    public function testTenantCannotListPlaylists(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::TENANT), RbacEnforcer::ACTION_MANAGE_TERMINALS);
    }

    public function testTenantCannotGetTransfer(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::TENANT), RbacEnforcer::ACTION_MANAGE_TERMINALS);
    }

    public function testFinanceClerkCannotCreateTerminal(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::FINANCE_CLERK), RbacEnforcer::ACTION_MANAGE_TERMINALS);
    }

    // ─── Audit: VIEW_AUDIT ─────────────────────────────────────────────────

    public function testAdministratorCanViewAuditLogs(): void
    {
        $this->rbac->enforce($this->makeUser(UserRole::ADMINISTRATOR), RbacEnforcer::ACTION_VIEW_AUDIT);
        $this->addToAssertionCount(1);
    }

    public function testPropertyManagerCannotViewAuditLogs(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::PROPERTY_MANAGER), RbacEnforcer::ACTION_VIEW_AUDIT);
    }

    public function testTenantCannotViewAuditLogs(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::TENANT), RbacEnforcer::ACTION_VIEW_AUDIT);
    }

    public function testFinanceClerkCannotViewAuditLogs(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::FINANCE_CLERK), RbacEnforcer::ACTION_VIEW_AUDIT);
    }

    // ─── Backup: MANAGE_BACKUPS ────────────────────────────────────────────

    public function testAdministratorCanManageBackups(): void
    {
        $this->rbac->enforce($this->makeUser(UserRole::ADMINISTRATOR), RbacEnforcer::ACTION_MANAGE_BACKUPS);
        $this->addToAssertionCount(1);
    }

    public function testPropertyManagerCannotManageBackups(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::PROPERTY_MANAGER), RbacEnforcer::ACTION_MANAGE_BACKUPS);
    }

    public function testTenantCannotManageBackups(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::TENANT), RbacEnforcer::ACTION_MANAGE_BACKUPS);
    }

    public function testFinanceClerkCannotManageBackups(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($this->makeUser(UserRole::FINANCE_CLERK), RbacEnforcer::ACTION_MANAGE_BACKUPS);
    }
}
