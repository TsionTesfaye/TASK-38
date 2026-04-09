<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BackupService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BackupServiceTest extends TestCase
{
    private string $tmpDir;
    private string $encKey = 'test-encryption-key-32-chars-min';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/backup_test_' . uniqid();
        mkdir($this->tmpDir, 0750, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files.
        foreach (glob($this->tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function makeUser(UserRole $role, string $orgId = 'org-1'): User
    {
        /** @var Organization&MockObject $org */
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn($orgId);

        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('getRole')->willReturn($role);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn($orgId);
        $user->method('getUsername')->willReturn('admin');

        return $user;
    }

    private function makeService(string $orgId = 'org-1'): BackupService
    {
        /** @var AbstractSchemaManager&MockObject $schema */
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['bills', 'bookings', 'nonexistent_table']);

        /** @var Connection&MockObject $conn */
        $conn = $this->createMock(Connection::class);
        $conn->method('createSchemaManager')->willReturn($schema);
        $conn->method('quoteIdentifier')->willReturnArgument(0);
        $conn->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params) {
            // Return dummy rows only for bills.
            if (str_contains($sql, 'bills')) {
                return [['id' => 'bill-1', 'organization_id' => $params['orgId'], 'amount' => '100.00']];
            }
            return [];
        });

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        /** @var OrganizationScope&MockObject $scope */
        $scope = $this->createMock(OrganizationScope::class);
        $scope->method('getOrganizationId')->willReturn($orgId);

        /** @var AuditService&MockObject $audit */
        $audit = $this->createMock(AuditService::class);

        return new BackupService($em, $scope, new RbacEnforcer(), $audit, $this->tmpDir, $this->encKey);
    }

    // ─── createBackup ──────────────────────────────────────────────────────

    public function testAdminCanCreateBackup(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $service = $this->makeService();

        $result = $service->createBackup($admin);

        $this->assertArrayHasKey('filename', $result);
        $this->assertStringStartsWith('backup_org-1_', $result['filename']);
        $this->assertFileExists($this->tmpDir . '/' . $result['filename']);
    }

    public function testPropertyManagerCannotCreateBackup(): void
    {
        $manager = $this->makeUser(UserRole::PROPERTY_MANAGER);
        $service = $this->makeService();

        $this->expectException(AccessDeniedException::class);
        $service->createBackup($manager);
    }

    public function testTenantCannotCreateBackup(): void
    {
        $tenant = $this->makeUser(UserRole::TENANT);
        $service = $this->makeService();

        $this->expectException(AccessDeniedException::class);
        $service->createBackup($tenant);
    }

    public function testBackupOnlyIncludesAllowedTables(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $service = $this->makeService();

        $result = $service->createBackup($admin);

        // 'nonexistent_table' is not in ORGANISATION_TABLES — should be excluded.
        $this->assertNotContains('nonexistent_table', $result['tables']);
        // 'bills' IS in ORGANISATION_TABLES — should be included.
        $this->assertContains('bills', $result['tables']);
    }

    // ─── previewRestore ────────────────────────────────────────────────────

    public function testPreviewRestoreShowsRecordCounts(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $service = $this->makeService();

        $backup = $service->createBackup($admin);
        $preview = $service->previewRestore($admin, $backup['filename']);

        $this->assertArrayHasKey('metadata', $preview);
        $this->assertArrayHasKey('record_counts', $preview);
        $this->assertSame('org-1', $preview['metadata']['organization_id']);
        $this->assertSame(1, $preview['record_counts']['bills']);
    }

    public function testPreviewRestoreThrowsForMissingFile(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->previewRestore($admin, '../../../etc/passwd');
    }

    public function testPreviewRestoreRejectsPathTraversal(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $service = $this->makeService();

        // basename() strips directory traversal, then file doesn't exist → EntityNotFoundException
        // Either InvalidArgumentException (bad format) or EntityNotFoundException (not found) is acceptable
        // — the point is it must NOT access the traversed path.
        $this->expectException(\Exception::class);
        $service->previewRestore($admin, '../../etc/passwd');
    }

    public function testPreviewRestoreRejectsCrossOrgBackup(): void
    {
        // Create a backup for org-1.
        $admin1 = $this->makeUser(UserRole::ADMINISTRATOR, 'org-1');
        $service1 = $this->makeService('org-1');
        $backup = $service1->createBackup($admin1);

        // Try to preview it as org-2.
        $admin2 = $this->makeUser(UserRole::ADMINISTRATOR, 'org-2');

        /** @var AbstractSchemaManager&MockObject $schema */
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['bills']);

        /** @var Connection&MockObject $conn */
        $conn = $this->createMock(Connection::class);
        $conn->method('createSchemaManager')->willReturn($schema);
        $conn->method('quoteIdentifier')->willReturnArgument(0);
        $conn->method('fetchAllAssociative')->willReturn([]);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        /** @var OrganizationScope&MockObject $scope */
        $scope = $this->createMock(OrganizationScope::class);
        $scope->method('getOrganizationId')->willReturn('org-2');

        /** @var AuditService&MockObject $audit */
        $audit = $this->createMock(AuditService::class);

        $service2 = new BackupService($em, $scope, new RbacEnforcer(), $audit, $this->tmpDir, $this->encKey);

        $this->expectException(AccessDeniedException::class);
        $service2->previewRestore($admin2, $backup['filename']);
    }

    // ─── restore ───────────────────────────────────────────────────────────

    public function testRestoreExecutesInTransactionWithFkChecks(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);

        /** @var AbstractSchemaManager&MockObject $schema */
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['bills']);

        $statements = [];

        /** @var Connection&MockObject $conn */
        $conn = $this->createMock(Connection::class);
        $conn->method('createSchemaManager')->willReturn($schema);
        $conn->method('quoteIdentifier')->willReturnArgument(0);
        $conn->method('fetchAllAssociative')->willReturn(
            [['id' => 'b1', 'organization_id' => 'org-1', 'amount' => '50.00']]
        );
        $conn->expects($this->once())->method('beginTransaction');
        $conn->expects($this->once())->method('commit');
        $conn->expects($this->never())->method('rollBack');
        $conn->method('executeStatement')->willReturnCallback(function (string $sql) use (&$statements) {
            $statements[] = $sql;
            return 1;
        });
        $conn->method('insert')->willReturn(1);
        // fetchOne is used by FK validation — return 0 (no orphans).
        $conn->method('fetchOne')->willReturn(0);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        /** @var OrganizationScope&MockObject $scope */
        $scope = $this->createMock(OrganizationScope::class);
        $scope->method('getOrganizationId')->willReturn('org-1');

        /** @var AuditService&MockObject $audit */
        $audit = $this->createMock(AuditService::class);

        $service = new BackupService($em, $scope, new RbacEnforcer(), $audit, $this->tmpDir, $this->encKey);

        $backup = $service->createBackup($admin);
        $result = $service->restore($admin, $backup['filename']);

        $this->assertSame($backup['filename'], $result['filename']);
        $this->assertArrayHasKey('restored_counts', $result);

        // Verify FK checks are disabled before data operations and re-enabled after.
        $this->assertSame('SET FOREIGN_KEY_CHECKS = 0', $statements[0], 'FK checks must be disabled first');
        $fkEnableIdx = array_search('SET FOREIGN_KEY_CHECKS = 1', $statements, true);
        $this->assertNotFalse($fkEnableIdx, 'FK checks must be re-enabled');
        // The DELETE statement must be between disable and enable.
        $deleteIdx = null;
        foreach ($statements as $i => $s) {
            if (str_contains($s, 'DELETE FROM')) {
                $deleteIdx = $i;
                break;
            }
        }
        $this->assertNotNull($deleteIdx);
        $this->assertGreaterThan(0, $deleteIdx, 'DELETE must come after FK disable');
        $this->assertLessThan($fkEnableIdx, $deleteIdx, 'DELETE must come before FK re-enable');
    }

    // ─── encryption integrity ─────────────────────────────────────────────

    public function testCorruptedBackupThrowsOnRestore(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);
        $service = $this->makeService();

        $backup = $service->createBackup($admin);
        $filepath = $this->tmpDir . '/' . $backup['filename'];

        // Corrupt the file.
        file_put_contents($filepath, 'corrupted-data-that-is-not-base64-valid!!');

        $this->expectException(\RuntimeException::class);
        $service->previewRestore($admin, $backup['filename']);
    }

    public function testEmptyEncryptionKeyThrowsOnConstruction(): void
    {
        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        /** @var OrganizationScope&MockObject $scope */
        $scope = $this->createMock(OrganizationScope::class);
        /** @var AuditService&MockObject $audit */
        $audit = $this->createMock(AuditService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_ENCRYPTION_KEY');
        new BackupService($em, $scope, new RbacEnforcer(), $audit, $this->tmpDir, '');
    }
}
