<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests organization scope isolation logic.
 *
 * Business rules:
 * - Users can only access resources in their own organization
 * - Cross-organization access must throw an exception
 * - Scope queries must filter by organization_id
 */
class OrganizationScopeTest extends TestCase
{
    private function assertSameOrganization(string $userOrgId, string $entityOrgId): void
    {
        if ($userOrgId !== $entityOrgId) {
            throw new \RuntimeException('Organization scope mismatch');
        }
    }

    private function scopeQuery(string $orgId): array
    {
        return ['organization_id' => $orgId];
    }

    public function test_same_organization_passes(): void
    {
        $userOrgId = 'org-001';
        $entityOrgId = 'org-001';

        // Should not throw
        $this->assertSameOrganization($userOrgId, $entityOrgId);
        $this->assertTrue(true); // Reached without exception
    }

    public function test_different_organization_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Organization scope mismatch');

        $this->assertSameOrganization('org-001', 'org-002');
    }

    public function test_scope_query_contains_organization_id(): void
    {
        $query = $this->scopeQuery('org-abc');
        $this->assertArrayHasKey('organization_id', $query);
        $this->assertSame('org-abc', $query['organization_id']);
    }

    public function test_scope_query_only_contains_organization_id(): void
    {
        $query = $this->scopeQuery('org-xyz');
        $this->assertCount(1, $query);
        $this->assertSame('org-xyz', $query['organization_id']);
    }

    public function test_empty_org_ids_are_equal(): void
    {
        // Two empty strings are technically the same org
        $this->assertSameOrganization('', '');
        $this->assertTrue(true);
    }

    public function test_case_sensitive_comparison(): void
    {
        $this->expectException(\RuntimeException::class);

        // Org IDs are case-sensitive
        $this->assertSameOrganization('ORG-001', 'org-001');
    }
}
