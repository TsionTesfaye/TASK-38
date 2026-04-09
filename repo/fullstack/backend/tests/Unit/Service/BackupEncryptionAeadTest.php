<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BackupService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Validates AES-256-GCM (AEAD) encryption for backups:
 *
 *   1. Round-trip: encrypt → decrypt returns original data
 *   2. Tampered ciphertext fails decryption
 *   3. Tampered auth tag fails validation
 *   4. Tampered nonce fails decryption
 *   5. Wrong key fails decryption
 *   6. Truncated data fails
 *   7. Each encryption produces a unique nonce
 */
class BackupEncryptionAeadTest extends TestCase
{
    private string $tmpDir;
    private string $encKey = 'test-encryption-key-32-chars-min';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/backup_aead_test_' . uniqid();
        mkdir($this->tmpDir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function makeUser(UserRole $role = UserRole::ADMINISTRATOR, string $orgId = 'org-1'): User
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn($orgId);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('getRole')->willReturn($role);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn($orgId);
        $user->method('getUsername')->willReturn('admin');

        return $user;
    }

    private function makeService(string $key = null, string $orgId = 'org-1'): BackupService
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['bills']);

        $conn = $this->createMock(Connection::class);
        $conn->method('createSchemaManager')->willReturn($schema);
        $conn->method('quoteIdentifier')->willReturnArgument(0);
        $conn->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params) {
            if (str_contains($sql, 'bills')) {
                return [['id' => 'bill-1', 'organization_id' => $params['orgId'], 'amount' => '100.00']];
            }
            return [];
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $scope = $this->createMock(OrganizationScope::class);
        $scope->method('getOrganizationId')->willReturn($orgId);

        $audit = $this->createMock(AuditService::class);

        return new BackupService(
            $em, $scope, new RbacEnforcer(), $audit,
            $this->tmpDir, $key ?? $this->encKey,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. Round-trip: create + preview returns correct data
    // ═══════════════════════════════════════════════════════════════

    public function testRoundTripEncryptDecrypt(): void
    {
        $admin = $this->makeUser();
        $service = $this->makeService();

        $backup = $service->createBackup($admin);
        $preview = $service->previewRestore($admin, $backup['filename']);

        $this->assertSame('org-1', $preview['metadata']['organization_id']);
        $this->assertSame(1, $preview['record_counts']['bills']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. Tampered ciphertext fails decryption
    // ═══════════════════════════════════════════════════════════════

    public function testTamperedCiphertextFailsDecryption(): void
    {
        $admin = $this->makeUser();
        $service = $this->makeService();

        $backup = $service->createBackup($admin);
        $filepath = $this->tmpDir . '/' . $backup['filename'];

        // Read, decode, tamper with ciphertext bytes, re-encode
        $raw = base64_decode(file_get_contents($filepath), true);
        // Flip a byte in the ciphertext region (after nonce + tag = 28 bytes)
        $tamperPos = 30;
        if (strlen($raw) > $tamperPos) {
            $raw[$tamperPos] = chr(ord($raw[$tamperPos]) ^ 0xFF);
        }
        file_put_contents($filepath, base64_encode($raw));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        $service->previewRestore($admin, $backup['filename']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. Tampered auth tag fails validation
    // ═══════════════════════════════════════════════════════════════

    public function testTamperedAuthTagFailsValidation(): void
    {
        $admin = $this->makeUser();
        $service = $this->makeService();

        $backup = $service->createBackup($admin);
        $filepath = $this->tmpDir . '/' . $backup['filename'];

        $raw = base64_decode(file_get_contents($filepath), true);
        // Tamper the tag region (bytes 12-27)
        $raw[14] = chr(ord($raw[14]) ^ 0xFF);
        file_put_contents($filepath, base64_encode($raw));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        $service->previewRestore($admin, $backup['filename']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. Tampered nonce fails decryption
    // ═══════════════════════════════════════════════════════════════

    public function testTamperedNonceFailsDecryption(): void
    {
        $admin = $this->makeUser();
        $service = $this->makeService();

        $backup = $service->createBackup($admin);
        $filepath = $this->tmpDir . '/' . $backup['filename'];

        $raw = base64_decode(file_get_contents($filepath), true);
        // Tamper the nonce (first 12 bytes)
        $raw[0] = chr(ord($raw[0]) ^ 0xFF);
        file_put_contents($filepath, base64_encode($raw));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        $service->previewRestore($admin, $backup['filename']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. Wrong key fails decryption
    // ═══════════════════════════════════════════════════════════════

    public function testWrongKeyFailsDecryption(): void
    {
        $admin = $this->makeUser();
        $service1 = $this->makeService('correct-key-32-chars-long!!!!!!');

        $backup = $service1->createBackup($admin);

        // Try to decrypt with a different key
        $service2 = $this->makeService('wrong-key-32-chars-long-padding!');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        $service2->previewRestore($admin, $backup['filename']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 6. Truncated data fails
    // ═══════════════════════════════════════════════════════════════

    public function testTruncatedBackupFails(): void
    {
        $admin = $this->makeUser();
        $service = $this->makeService();

        $backup = $service->createBackup($admin);
        $filepath = $this->tmpDir . '/' . $backup['filename'];

        // Truncate to just a few bytes
        file_put_contents($filepath, base64_encode('short'));

        $this->expectException(\RuntimeException::class);
        $service->previewRestore($admin, $backup['filename']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 7. Each encryption produces a unique nonce
    // ═══════════════════════════════════════════════════════════════

    public function testUniqueNoncePerEncryption(): void
    {
        $admin = $this->makeUser();
        $service = $this->makeService();

        $backup1 = $service->createBackup($admin);
        // Need a brief delay so filename differs
        usleep(1100000);
        $backup2 = $service->createBackup($admin);

        $raw1 = base64_decode(file_get_contents($this->tmpDir . '/' . $backup1['filename']), true);
        $raw2 = base64_decode(file_get_contents($this->tmpDir . '/' . $backup2['filename']), true);

        $nonce1 = substr($raw1, 0, 12);
        $nonce2 = substr($raw2, 0, 12);

        $this->assertNotSame(
            $nonce1,
            $nonce2,
            'Each encryption must use a unique nonce'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 8. Empty encryption key still rejected
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyEncryptionKeyThrowsOnConstruction(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $scope = $this->createMock(OrganizationScope::class);
        $audit = $this->createMock(AuditService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_ENCRYPTION_KEY');
        new BackupService($em, $scope, new RbacEnforcer(), $audit, $this->tmpDir, '');
    }
}
