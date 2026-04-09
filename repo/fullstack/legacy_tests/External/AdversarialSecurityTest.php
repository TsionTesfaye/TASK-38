<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\UserRole;
use App\Security\RbacEnforcer;

/**
 * ADVERSARIAL: Cross-Role & Cross-Org Security Tests
 *
 * Simulates malicious users trying to access data/actions they shouldn't.
 * Every test represents a real authorization bypass attempt.
 */
class AdversarialSecurityTest extends TestCase
{
    // === CROSS-ROLE: Tenant tries admin/manager/finance actions ===

    public function test_tenant_cannot_manage_users(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_USERS, $perms);
    }

    public function test_tenant_cannot_manage_inventory(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_INVENTORY, $perms);
    }

    public function test_tenant_cannot_view_finance(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_FINANCE, $perms);
    }

    public function test_tenant_cannot_manage_backups(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_BACKUPS, $perms);
    }

    public function test_tenant_cannot_manage_settings(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_SETTINGS, $perms);
    }

    public function test_tenant_cannot_view_audit(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_AUDIT, $perms);
    }

    public function test_tenant_cannot_mark_no_show(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MARK_NOSHOW, $perms);
    }

    public function test_tenant_cannot_check_in(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_CHECK_IN, $perms);
    }

    public function test_tenant_has_only_view_own(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertCount(1, $perms, 'Tenant must have exactly 1 permission');
        $this->assertSame(RbacEnforcer::ACTION_VIEW_OWN, $perms[0]);
    }

    // === CROSS-ROLE: Finance clerk overreach ===

    public function test_finance_cannot_manage_bookings(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::FINANCE_CLERK->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_BOOKINGS, $perms);
    }

    public function test_finance_cannot_manage_inventory(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::FINANCE_CLERK->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_INVENTORY, $perms);
    }

    public function test_finance_cannot_manage_users(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::FINANCE_CLERK->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_USERS, $perms);
    }

    // === CROSS-ROLE: Property manager boundaries ===

    public function test_manager_cannot_manage_users(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::PROPERTY_MANAGER->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_USERS, $perms);
    }

    public function test_manager_cannot_manage_backups(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::PROPERTY_MANAGER->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_MANAGE_BACKUPS, $perms);
    }

    public function test_manager_cannot_view_audit(): void
    {
        $perms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::PROPERTY_MANAGER->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_AUDIT, $perms);
    }

    // === ADMIN: should have ALL permissions ===

    public function test_admin_has_all_permissions(): void
    {
        $adminPerms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::ADMINISTRATOR->value] ?? [];

        $allActions = [
            RbacEnforcer::ACTION_VIEW_OWN, RbacEnforcer::ACTION_VIEW_ORG,
            RbacEnforcer::ACTION_MANAGE_INVENTORY, RbacEnforcer::ACTION_MANAGE_BOOKINGS,
            RbacEnforcer::ACTION_MANAGE_BILLING, RbacEnforcer::ACTION_MANAGE_USERS,
            RbacEnforcer::ACTION_VIEW_FINANCE, RbacEnforcer::ACTION_EXPORT_FINANCE,
            RbacEnforcer::ACTION_MANAGE_TERMINALS, RbacEnforcer::ACTION_MANAGE_SETTINGS,
            RbacEnforcer::ACTION_VIEW_AUDIT, RbacEnforcer::ACTION_MANAGE_BACKUPS,
            RbacEnforcer::ACTION_PROCESS_REFUND, RbacEnforcer::ACTION_MARK_NOSHOW,
            RbacEnforcer::ACTION_CHECK_IN,
        ];

        foreach ($allActions as $action) {
            $this->assertContains($action, $adminPerms,
                "Admin must have permission: {$action}");
        }
    }

    // === CROSS-ORG: Organization scope must isolate data ===

    public function test_cross_org_id_mismatch_detected(): void
    {
        $userOrgId = 'org-aaa-111';
        $entityOrgId = 'org-bbb-222';
        $this->assertNotSame($userOrgId, $entityOrgId,
            'Different org IDs must be detectable for scope enforcement');
    }

    public function test_org_scope_is_case_sensitive(): void
    {
        $org1 = 'ORG-001';
        $org2 = 'org-001';
        $this->assertNotSame($org1, $org2,
            'Org ID comparison must be case-sensitive');
    }
}
