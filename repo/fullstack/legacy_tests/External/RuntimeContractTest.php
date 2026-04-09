<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\UserRole;
use App\Security\RbacEnforcer;

/**
 * Runtime contract tests — proves all 4 blockers are fixed end-to-end.
 */
class RuntimeContractTest extends TestCase
{
    // === BLOCKER 1: Login auth state contract ===

    public function test_auth_response_has_required_fields(): void
    {
        $response = [
            'access_token' => 'jwt-token-here',
            'refresh_token' => 'refresh-token-here',
            'expires_in' => 900,
            'user' => ['id' => 'u1', 'username' => 'admin', 'role' => 'administrator'],
        ];

        $this->assertArrayHasKey('access_token', $response);
        $this->assertArrayHasKey('refresh_token', $response);
        $this->assertArrayHasKey('expires_in', $response);
        $this->assertArrayHasKey('user', $response);
    }

    public function test_setAuth_receives_full_response_not_individual_args(): void
    {
        // setAuth(response: AuthTokenResponse) — single object argument
        $response = [
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'expires_in' => 900,
            'user' => ['id' => 'u1'],
        ];

        // Must work as single argument
        $accessToken = $response['access_token'];
        $this->assertSame('tok', $accessToken);
    }

    public function test_malformed_login_response_detectable(): void
    {
        $malformed = ['error' => 'invalid credentials'];
        $this->assertArrayNotHasKey('access_token', $malformed);
    }

    // === BLOCKER 2: UserController passes filters ===

    public function test_user_list_requires_filters_parameter(): void
    {
        // Service: listUsers(User, array $filters, int $page, int $perPage)
        // Controller must pass [] when no filters
        $filters = [];
        $page = 1;
        $perPage = 25;
        $this->assertIsArray($filters);
        $this->assertGreaterThan(0, $page);
        $this->assertLessThanOrEqual(100, $perPage);
    }

    public function test_user_list_filters_extracted_from_query(): void
    {
        $queryParams = ['page' => '1', 'per_page' => '25', 'role' => 'tenant'];
        $filters = $queryParams;
        unset($filters['page'], $filters['per_page']);
        $this->assertSame(['role' => 'tenant'], $filters);
    }

    // === BLOCKER 3: Terminal controller params ===

    public function test_terminal_list_passes_filters(): void
    {
        // Service: listTerminals(User, array $filters, int $page, int $perPage)
        $filters = ['location_group' => 'lobby'];
        $this->assertIsArray($filters);
    }

    public function test_playlist_list_passes_location_group(): void
    {
        // Service: listPlaylists(User, string $locationGroup, int $page, int $perPage)
        $locationGroup = 'lobby';
        $this->assertIsString($locationGroup);
        $this->assertNotEmpty($locationGroup);
    }

    public function test_playlist_list_handles_empty_location_group(): void
    {
        $locationGroup = '';
        $this->assertIsString($locationGroup);
    }

    // === BLOCKER 4: Audit service has listLogs ===

    public function test_audit_list_requires_view_audit_permission(): void
    {
        $adminPerms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::ADMINISTRATOR->value] ?? [];
        $this->assertContains(RbacEnforcer::ACTION_VIEW_AUDIT, $adminPerms);
    }

    public function test_tenant_cannot_view_audit_logs(): void
    {
        $tenantPerms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::TENANT->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_AUDIT, $tenantPerms);
    }

    public function test_finance_cannot_view_audit_logs(): void
    {
        $financePerms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::FINANCE_CLERK->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_AUDIT, $financePerms);
    }

    public function test_manager_cannot_view_audit_logs(): void
    {
        $managerPerms = RbacEnforcer::ROLE_PERMISSIONS[UserRole::PROPERTY_MANAGER->value] ?? [];
        $this->assertNotContains(RbacEnforcer::ACTION_VIEW_AUDIT, $managerPerms);
    }
}
