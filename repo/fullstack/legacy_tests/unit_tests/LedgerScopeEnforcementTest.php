<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Security\RbacEnforcer;
use App\Enum\UserRole;

class LedgerScopeEnforcementTest extends TestCase
{
    public function test_finance_clerk_can_view_finance(): void
    {
        $permissions = RbacEnforcer::ROLE_PERMISSIONS[UserRole::FINANCE_CLERK->value] ?? [];
        $this->assertContains(RbacEnforcer::ACTION_VIEW_FINANCE, $permissions);
    }

    public function test_administrator_can_view_finance(): void
    {
        $permissions = RbacEnforcer::ROLE_PERMISSIONS[UserRole::ADMINISTRATOR->value] ?? [];
        $this->assertContains(RbacEnforcer::ACTION_VIEW_FINANCE, $permissions);
    }

    public function test_tenant_cannot_view_finance(): void
    {
        $permissions = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_FINANCE, $permissions);
    }

    public function test_property_manager_cannot_view_finance(): void
    {
        $permissions = RbacEnforcer::ROLE_PERMISSIONS[UserRole::PROPERTY_MANAGER->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_FINANCE, $permissions);
    }

    public function test_metrics_requires_view_audit(): void
    {
        // MetricsController now enforces ACTION_VIEW_AUDIT
        // Only administrator has this permission
        $adminPerms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::ADMINISTRATOR->value] ?? [];
        $this->assertContains(RbacEnforcer::ACTION_VIEW_AUDIT, $adminPerms);

        $tenantPerms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_AUDIT, $tenantPerms);

        $managerPerms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::PROPERTY_MANAGER->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_AUDIT, $managerPerms);

        $financePerms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::FINANCE_CLERK->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_AUDIT, $financePerms);
    }
}
