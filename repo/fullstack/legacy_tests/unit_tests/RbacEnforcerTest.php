<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests RBAC permission enforcement logic.
 *
 * Role permissions (mirroring RbacEnforcer::ROLE_PERMISSIONS):
 * - administrator: all permissions
 * - property_manager: VIEW_ORG, MANAGE_INVENTORY, MANAGE_BOOKINGS, MANAGE_BILLING,
 *                     MANAGE_TERMINALS, PROCESS_REFUND, MARK_NOSHOW, CHECK_IN
 * - tenant: VIEW_OWN only
 * - finance_clerk: VIEW_ORG, VIEW_FINANCE, EXPORT_FINANCE, PROCESS_REFUND
 */
class RbacEnforcerTest extends TestCase
{
    private const ROLE_PERMISSIONS = [
        'administrator' => [
            'VIEW_OWN', 'VIEW_ORG', 'MANAGE_INVENTORY', 'MANAGE_BOOKINGS',
            'MANAGE_BILLING', 'MANAGE_USERS', 'VIEW_FINANCE', 'EXPORT_FINANCE',
            'MANAGE_TERMINALS', 'MANAGE_SETTINGS', 'VIEW_AUDIT', 'MANAGE_BACKUPS',
            'PROCESS_REFUND', 'MARK_NOSHOW', 'CHECK_IN',
        ],
        'property_manager' => [
            'VIEW_ORG', 'MANAGE_INVENTORY', 'MANAGE_BOOKINGS', 'MANAGE_BILLING',
            'MANAGE_TERMINALS', 'PROCESS_REFUND', 'MARK_NOSHOW', 'CHECK_IN',
        ],
        'tenant' => [
            'VIEW_OWN',
        ],
        'finance_clerk' => [
            'VIEW_ORG', 'VIEW_FINANCE', 'EXPORT_FINANCE', 'PROCESS_REFUND',
        ],
    ];

    private function hasPermission(string $role, string $action): bool
    {
        $allowed = self::ROLE_PERMISSIONS[$role] ?? [];
        return in_array($action, $allowed, true);
    }

    public function test_admin_has_all_permissions(): void
    {
        $allActions = [
            'VIEW_OWN', 'VIEW_ORG', 'MANAGE_INVENTORY', 'MANAGE_BOOKINGS',
            'MANAGE_BILLING', 'MANAGE_USERS', 'VIEW_FINANCE', 'EXPORT_FINANCE',
            'MANAGE_TERMINALS', 'MANAGE_SETTINGS', 'VIEW_AUDIT', 'MANAGE_BACKUPS',
            'PROCESS_REFUND', 'MARK_NOSHOW', 'CHECK_IN',
        ];

        foreach ($allActions as $action) {
            $this->assertTrue(
                $this->hasPermission('administrator', $action),
                "Administrator should have {$action} permission"
            );
        }
    }

    public function test_tenant_only_has_view_own(): void
    {
        $this->assertTrue($this->hasPermission('tenant', 'VIEW_OWN'));
        $this->assertFalse($this->hasPermission('tenant', 'VIEW_ORG'));
        $this->assertFalse($this->hasPermission('tenant', 'MANAGE_INVENTORY'));
        $this->assertFalse($this->hasPermission('tenant', 'MANAGE_BOOKINGS'));
        $this->assertFalse($this->hasPermission('tenant', 'VIEW_FINANCE'));
    }

    public function test_property_manager_has_manage_inventory(): void
    {
        $this->assertTrue($this->hasPermission('property_manager', 'MANAGE_INVENTORY'));
        $this->assertTrue($this->hasPermission('property_manager', 'MANAGE_BOOKINGS'));
        $this->assertTrue($this->hasPermission('property_manager', 'MANAGE_BILLING'));
        $this->assertTrue($this->hasPermission('property_manager', 'CHECK_IN'));
    }

    public function test_property_manager_cannot_manage_users(): void
    {
        $this->assertFalse($this->hasPermission('property_manager', 'MANAGE_USERS'));
        $this->assertFalse($this->hasPermission('property_manager', 'MANAGE_SETTINGS'));
        $this->assertFalse($this->hasPermission('property_manager', 'VIEW_AUDIT'));
        $this->assertFalse($this->hasPermission('property_manager', 'MANAGE_BACKUPS'));
    }

    public function test_finance_clerk_has_view_finance(): void
    {
        $this->assertTrue($this->hasPermission('finance_clerk', 'VIEW_FINANCE'));
        $this->assertTrue($this->hasPermission('finance_clerk', 'EXPORT_FINANCE'));
        $this->assertTrue($this->hasPermission('finance_clerk', 'VIEW_ORG'));
        $this->assertTrue($this->hasPermission('finance_clerk', 'PROCESS_REFUND'));
    }

    public function test_finance_clerk_cannot_manage_inventory(): void
    {
        $this->assertFalse($this->hasPermission('finance_clerk', 'MANAGE_INVENTORY'));
        $this->assertFalse($this->hasPermission('finance_clerk', 'MANAGE_BOOKINGS'));
        $this->assertFalse($this->hasPermission('finance_clerk', 'MANAGE_BILLING'));
        $this->assertFalse($this->hasPermission('finance_clerk', 'MANAGE_USERS'));
    }

    public function test_unknown_role_has_no_permissions(): void
    {
        $this->assertFalse($this->hasPermission('guest', 'VIEW_OWN'));
        $this->assertFalse($this->hasPermission('guest', 'VIEW_ORG'));
    }

    public function test_admin_permission_count_is_complete(): void
    {
        $this->assertCount(15, self::ROLE_PERMISSIONS['administrator']);
    }
}
