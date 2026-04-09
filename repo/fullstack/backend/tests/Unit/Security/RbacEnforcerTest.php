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

class RbacEnforcerTest extends TestCase
{
    private RbacEnforcer $rbac;

    protected function setUp(): void
    {
        $this->rbac = new RbacEnforcer();
    }

    private function makeUser(UserRole $role, string $userId = 'user-1'): User
    {
        /** @var Organization&MockObject $org */
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($userId);
        $user->method('getRole')->willReturn($role);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');

        return $user;
    }

    // ─── Administrator has all permissions ─────────────────────────────────

    public function testAdministratorCanManageUsers(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_USERS);
        $this->addToAssertionCount(1); // no exception = pass
    }

    public function testAdministratorCanManageBackups(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_BACKUPS);
        $this->addToAssertionCount(1);
    }

    public function testAdministratorCanExportFinance(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_EXPORT_FINANCE);
        $this->addToAssertionCount(1);
    }

    // ─── TENANT is restricted ──────────────────────────────────────────────

    public function testTenantCannotManageUsers(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT);

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($tenant, RbacEnforcer::ACTION_MANAGE_USERS);
    }

    public function testTenantCannotViewFinance(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT);

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_FINANCE);
    }

    public function testTenantCannotManageInventory(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT);

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($tenant, RbacEnforcer::ACTION_MANAGE_INVENTORY);
    }

    public function testTenantCannotExportFinance(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT);

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($tenant, RbacEnforcer::ACTION_EXPORT_FINANCE);
    }

    // ─── TENANT VIEW_OWN resource scoping ─────────────────────────────────

    public function testTenantCanViewOwnResource(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'user-42');
        $this->rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_OWN, 'user-42');
        $this->addToAssertionCount(1);
    }

    public function testTenantCannotViewOtherUsersResource(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT, 'user-42');

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_OWN, 'user-99');
    }

    // ─── FINANCE_CLERK permissions ─────────────────────────────────────────

    public function testFinanceClerkCanViewFinance(): void
    {
        $clerk = $this->makeUser(UserRole::FINANCE_CLERK);
        $this->rbac->enforce($clerk, RbacEnforcer::ACTION_VIEW_FINANCE);
        $this->addToAssertionCount(1);
    }

    public function testFinanceClerkCannotManageInventory(): void
    {
        $clerk = $this->makeUser(UserRole::FINANCE_CLERK);

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($clerk, RbacEnforcer::ACTION_MANAGE_INVENTORY);
    }

    public function testFinanceClerkCannotManageUsers(): void
    {
        $clerk = $this->makeUser(UserRole::FINANCE_CLERK);

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($clerk, RbacEnforcer::ACTION_MANAGE_USERS);
    }

    public function testFinanceClerkCannotManageBackups(): void
    {
        $clerk = $this->makeUser(UserRole::FINANCE_CLERK);

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($clerk, RbacEnforcer::ACTION_MANAGE_BACKUPS);
    }

    // ─── PROPERTY_MANAGER permissions ─────────────────────────────────────

    public function testPropertyManagerCanManageInventory(): void
    {
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_INVENTORY);
        $this->addToAssertionCount(1);
    }

    public function testPropertyManagerCanCheckIn(): void
    {
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_CHECK_IN);
        $this->addToAssertionCount(1);
    }

    public function testPropertyManagerCannotManageUsers(): void
    {
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_USERS);
    }

    public function testPropertyManagerCannotManageBackups(): void
    {
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_BACKUPS);
    }

    // ─── Finance clerk cannot view audit logs (admin-only) ────────────────

    public function testFinanceClerkCannotViewAuditLogs(): void
    {
        $clerk = $this->makeUser(UserRole::FINANCE_CLERK);

        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($clerk, RbacEnforcer::ACTION_VIEW_AUDIT);
    }
}
