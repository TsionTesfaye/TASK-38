<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Security\RbacEnforcer;
use PHPUnit\Framework\TestCase;

/**
 * Authoritative RBAC authorization matrix.
 *
 * Documents exactly which role is permitted to invoke which action,
 * and serves as regression protection for the permission map in
 * RbacEnforcer::ROLE_PERMISSIONS. Any accidental widening or
 * narrowing of a role's permissions will fail this suite.
 */
class RbacMatrixTest extends TestCase
{
    private RbacEnforcer $rbac;
    private Organization $org;

    protected function setUp(): void
    {
        $this->rbac = new RbacEnforcer();
        $this->org = new Organization('org-m', 'ORGM', 'Org M', 'USD');
    }

    private function user(UserRole $role): User
    {
        return new User('u-' . $role->value, $this->org, $role->value, 'h', 'N', $role);
    }

    /**
     * @return array<string, array{UserRole, string, bool}>
     *   Keyed matrix: expected_allow = true means role should be permitted.
     */
    public static function matrix(): array
    {
        $a = RbacEnforcer::class;
        // Construct [role, action, expected]
        $rows = [];

        $all = [
            $a::ACTION_VIEW_OWN,
            $a::ACTION_VIEW_ORG,
            $a::ACTION_MANAGE_INVENTORY,
            $a::ACTION_MANAGE_BOOKINGS,
            $a::ACTION_MANAGE_BILLING,
            $a::ACTION_MANAGE_USERS,
            $a::ACTION_VIEW_FINANCE,
            $a::ACTION_EXPORT_FINANCE,
            $a::ACTION_MANAGE_TERMINALS,
            $a::ACTION_MANAGE_SETTINGS,
            $a::ACTION_VIEW_SETTINGS,
            $a::ACTION_VIEW_AUDIT,
            $a::ACTION_MANAGE_BACKUPS,
            $a::ACTION_PROCESS_REFUND,
            $a::ACTION_MARK_NOSHOW,
            $a::ACTION_CHECK_IN,
        ];

        $allowByRole = [
            'administrator' => $all, // admin has everything
            'property_manager' => [
                $a::ACTION_VIEW_ORG,
                $a::ACTION_MANAGE_INVENTORY,
                $a::ACTION_MANAGE_BOOKINGS,
                $a::ACTION_MANAGE_BILLING,
                $a::ACTION_MANAGE_TERMINALS,
                $a::ACTION_VIEW_SETTINGS,
                $a::ACTION_PROCESS_REFUND,
                $a::ACTION_MARK_NOSHOW,
                $a::ACTION_CHECK_IN,
            ],
            'finance_clerk' => [
                $a::ACTION_VIEW_ORG,
                $a::ACTION_VIEW_FINANCE,
                $a::ACTION_EXPORT_FINANCE,
                $a::ACTION_VIEW_SETTINGS,
                $a::ACTION_PROCESS_REFUND,
            ],
            'tenant' => [
                $a::ACTION_VIEW_OWN,
            ],
        ];

        foreach ($allowByRole as $roleValue => $allowed) {
            foreach ($all as $action) {
                $expected = in_array($action, $allowed, true);
                $role = UserRole::from($roleValue);
                $rows["{$roleValue}:{$action}"] = [$role, $action, $expected];
            }
        }
        return $rows;
    }

    /** @dataProvider matrix */
    public function testAuthorizationMatrix(UserRole $role, string $action, bool $expected): void
    {
        $user = $this->user($role);
        if ($expected) {
            // Expect no exception
            $this->rbac->enforce($user, $action);
            $this->assertTrue(true);
        } else {
            $this->expectException(AccessDeniedException::class);
            $this->rbac->enforce($user, $action);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Tenant self-ownership check
    // ═══════════════════════════════════════════════════════════════

    public function testTenantViewOwnAllowedForSelf(): void
    {
        $tenant = $this->user(UserRole::TENANT);
        // Should not throw
        $this->rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_OWN, $tenant->getId());
        $this->assertTrue(true);
    }

    public function testTenantViewOwnDeniedForOtherUser(): void
    {
        $tenant = $this->user(UserRole::TENANT);
        $this->expectException(AccessDeniedException::class);
        $this->rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_OWN, 'other-user-id');
    }

    public function testTenantViewOwnAllowedWhenResourceIdNull(): void
    {
        $tenant = $this->user(UserRole::TENANT);
        // Null resourceOwnerId skips the self-ownership check — used for list endpoints
        $this->rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_OWN, null);
        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════════════════════════
    // Unknown action deny-by-default
    // ═══════════════════════════════════════════════════════════════

    public function testUnknownActionDeniedForAllRoles(): void
    {
        foreach ([UserRole::ADMINISTRATOR, UserRole::PROPERTY_MANAGER, UserRole::FINANCE_CLERK, UserRole::TENANT] as $role) {
            $user = $this->user($role);
            $threw = false;
            try {
                $this->rbac->enforce($user, 'NOT_A_REAL_ACTION');
            } catch (AccessDeniedException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "Role {$role->value} should reject unknown action");
        }
    }
}
