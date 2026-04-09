<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class BackupService
{
    /**
     * AES-256-GCM — Authenticated Encryption with Associated Data (AEAD).
     *
     * Encryption format (all binary, then base64-encoded):
     *   [12-byte nonce][16-byte GCM auth tag][ciphertext]
     *
     * Key usage:
     *   - The key is derived from BACKUP_ENCRYPTION_KEY env var.
     *   - A unique 12-byte random nonce is generated per encryption.
     *   - The GCM auth tag (16 bytes) is produced by the cipher and
     *     verified automatically during decryption — any tampering of
     *     nonce, ciphertext, or tag causes decryption to fail.
     *
     * Rotation:
     *   - To rotate the key, decrypt all backups with the old key and
     *     re-encrypt with the new key. Old backups encrypted with a
     *     previous key will fail decryption until re-encrypted.
     */
    private const CIPHER = 'aes-256-gcm';

    /**
     * GCM nonce length in bytes. NIST recommends 96 bits (12 bytes)
     * for AES-GCM to avoid birthday-bound collision risk.
     */
    private const NONCE_LENGTH = 12;

    /**
     * GCM authentication tag length in bytes (128-bit tag).
     */
    private const TAG_LENGTH = 16;

    /**
     * Tables that are known to be organisation-scoped.
     * Only these are included in backups — prevents SQL errors on system tables
     * (doctrine_migration_versions, messenger_messages, etc.) that have no organization_id.
     */
    private const ORGANISATION_TABLES = [
        'audit_logs',
        'bills',
        'booking_holds',
        'bookings',
        'inventory_items',
        'inventory_pricing',
        'ledger_entries',
        'notifications',
        'payments',
        'reconciliation_runs',
        'refunds',
        'settings',
        'terminal_package_transfers',
        'terminal_playlists',
        'terminals',
        'users',
    ];

    /**
     * FK relationships among organisation-scoped tables.
     * Each entry: [child_table, child_column, parent_table].
     * Used to validate referential integrity after restore.
     */
    private const FK_CONSTRAINTS = [
        ['inventory_pricing', 'inventory_item_id', 'inventory_items'],
        ['booking_holds', 'inventory_item_id', 'inventory_items'],
        ['booking_holds', 'tenant_user_id', 'users'],
        ['bookings', 'inventory_item_id', 'inventory_items'],
        ['bookings', 'tenant_user_id', 'users'],
        ['bookings', 'source_hold_id', 'booking_holds'],
        ['bills', 'booking_id', 'bookings'],
        ['bills', 'tenant_user_id', 'users'],
        ['payments', 'bill_id', 'bills'],
        ['refunds', 'bill_id', 'bills'],
        ['refunds', 'payment_id', 'payments'],
        ['refunds', 'created_by_user_id', 'users'],
        ['ledger_entries', 'booking_id', 'bookings'],
        ['ledger_entries', 'bill_id', 'bills'],
        ['ledger_entries', 'payment_id', 'payments'],
        ['ledger_entries', 'refund_id', 'refunds'],
        ['notifications', 'user_id', 'users'],
        ['terminal_package_transfers', 'terminal_id', 'terminals'],
        ['audit_logs', 'actor_user_id', 'users'],
    ];

    /**
     * Enum-backed columns that must contain valid values.
     * Each entry: [table, column, allowed_values[]].
     */
    private const ENUM_CONSTRAINTS = [
        ['bills', 'bill_type', ['initial', 'recurring', 'supplemental', 'penalty']],
        ['bills', 'status', ['open', 'partially_paid', 'paid', 'partially_refunded', 'voided']],
        ['payments', 'status', ['pending', 'succeeded', 'failed', 'rejected']],
        ['refunds', 'status', ['issued', 'rejected']],
        ['ledger_entries', 'entry_type', ['bill_issued', 'payment_received', 'refund_issued', 'penalty_applied', 'bill_voided']],
        ['bookings', 'status', ['confirmed', 'active', 'completed', 'canceled', 'no_show']],
        ['booking_holds', 'status', ['active', 'converted', 'released', 'expired']],
        ['users', 'role', ['administrator', 'property_manager', 'tenant', 'finance_clerk']],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
        private readonly AuditService $auditService,
        private readonly string $backupStoragePath,
        private readonly string $backupEncryptionKey,
    ) {
        if ($this->backupEncryptionKey === '') {
            throw new \RuntimeException('BACKUP_ENCRYPTION_KEY environment variable is not set');
        }
    }

    public function createBackup(User $admin): array
    {
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_BACKUPS);
        $orgId = $this->orgScope->getOrganizationId($admin);

        return $this->doCreateBackup($orgId, $admin->getUsername());
    }

    /**
     * CLI-only: creates a backup for an organization without requiring an authenticated user.
     * Used by CreateBackupCommand for scheduled/automated backups.
     */
    public function createSystemBackup(string $orgId): array
    {
        return $this->doCreateBackup($orgId, 'system');
    }

    private function doCreateBackup(string $orgId, string $actorUsername): array
    {
        $timestamp = (new \DateTimeImmutable())->format('Ymd_His');
        $filename = sprintf('backup_%s_%s.enc', $orgId, $timestamp);
        $filepath = rtrim($this->backupStoragePath, '/') . '/' . $filename;

        if (!is_dir($this->backupStoragePath)) {
            mkdir($this->backupStoragePath, 0750, true);
        }

        $connection = $this->em->getConnection();

        // Only back up tables that exist in this schema AND are organisation-scoped.
        $existingTables = $connection->createSchemaManager()->listTableNames();
        $tables = array_values(array_intersect(self::ORGANISATION_TABLES, $existingTables));

        $dumpData = [
            'metadata' => [
                'organization_id' => $orgId,
                'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'created_by' => $actorUsername,
                'tables' => $tables,
            ],
            'data' => [],
        ];

        foreach ($tables as $table) {
            $rows = $connection->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE organization_id = :orgId', $connection->quoteIdentifier($table)),
                ['orgId' => $orgId],
            );
            if (!empty($rows)) {
                $dumpData['data'][$table] = $rows;
            }
        }

        $jsonPayload = json_encode($dumpData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $encrypted = $this->encrypt($jsonPayload);

        file_put_contents($filepath, $encrypted);

        $this->auditService->log(
            $orgId,
            null,
            $actorUsername,
            'BACKUP_CREATED',
            'Backup',
            $filename,
            null,
            ['filename' => $filename, 'tables' => $tables],
        );

        return [
            'filename' => $filename,
            'created_at' => $dumpData['metadata']['created_at'],
            'tables' => $tables,
        ];
    }

    public function listBackups(User $admin, int $page = 1, int $perPage = 25): array
    {
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_BACKUPS);
        $orgId = $this->orgScope->getOrganizationId($admin);

        $backups = [];
        $pattern = rtrim($this->backupStoragePath, '/') . '/backup_' . $orgId . '_*.enc';
        $files = glob($pattern);

        if ($files === false) {
            $files = [];
        }

        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size_bytes' => filesize($file),
                'modified_at' => (new \DateTimeImmutable('@' . filemtime($file)))->format(\DateTimeInterface::ATOM),
            ];
        }

        usort($backups, fn(array $a, array $b) => $b['modified_at'] <=> $a['modified_at']);

        $total = count($backups);
        $perPage = min($perPage, 100);
        $offset = ($page - 1) * $perPage;

        return [
            'data' => array_slice($backups, $offset, $perPage),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_next' => ($page * $perPage) < $total,
            ],
        ];
    }

    public function previewRestore(User $admin, string $filename): array
    {
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_BACKUPS);
        $orgId = $this->orgScope->getOrganizationId($admin);

        $filepath = $this->resolveBackupPath($filename, $orgId);

        $encrypted = file_get_contents($filepath);
        if ($encrypted === false) {
            throw new EntityNotFoundException('Backup', $filename);
        }

        $data = $this->decryptAndDecode($encrypted);

        if ($data['metadata']['organization_id'] !== $orgId) {
            throw new AccessDeniedException();
        }

        $tableCounts = [];
        foreach ($data['data'] as $table => $rows) {
            $tableCounts[$table] = count($rows);
        }

        return [
            'metadata' => $data['metadata'],
            'record_counts' => $tableCounts,
        ];
    }

    public function restore(User $admin, string $filename): array
    {
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_BACKUPS);
        $orgId = $this->orgScope->getOrganizationId($admin);

        $filepath = $this->resolveBackupPath($filename, $orgId);

        $encrypted = file_get_contents($filepath);
        if ($encrypted === false) {
            throw new EntityNotFoundException('Backup', $filename);
        }

        $data = $this->decryptAndDecode($encrypted);

        if ($data['metadata']['organization_id'] !== $orgId) {
            throw new AccessDeniedException();
        }

        // Validate that all tables in the backup are on the allow-list.
        $invalidTables = array_diff(array_keys($data['data']), self::ORGANISATION_TABLES);
        if (!empty($invalidTables)) {
            throw new \RuntimeException('Backup contains unexpected tables: ' . implode(', ', $invalidTables));
        }

        $connection = $this->em->getConnection();
        $restoredCounts = [];

        $connection->beginTransaction();
        try {
            // Disable FK checks so delete/insert order doesn't matter.
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($data['data'] as $table => $rows) {
                $connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE organization_id = :orgId', $connection->quoteIdentifier($table)),
                    ['orgId' => $orgId],
                );

                foreach ($rows as $row) {
                    $connection->insert($table, $row);
                }

                $restoredCounts[$table] = count($rows);
            }

            // Re-enable FK checks before validating integrity.
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

            // Validate that all FK relationships are intact.
            $violations = $this->validateForeignKeyIntegrity($connection, $orgId, array_keys($data['data']));
            if (!empty($violations)) {
                throw new \RuntimeException(
                    'Restore aborted — FK integrity violations detected: ' . implode('; ', $violations)
                );
            }

            // Validate that all enum-backed columns contain valid values.
            $enumViolations = $this->validateEnumIntegrity($connection, $orgId, array_keys($data['data']));
            if (!empty($enumViolations)) {
                throw new \RuntimeException(
                    'Restore aborted — invalid enum values detected: ' . implode('; ', $enumViolations)
                );
            }

            $connection->commit();
        } catch (\Throwable $e) {
            // Ensure FK checks are re-enabled even on rollback.
            try {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable) {
                // Best-effort; the connection may already be broken.
            }
            $connection->rollBack();
            throw $e;
        }

        $this->auditService->log(
            $orgId,
            $admin,
            $admin->getUsername(),
            'BACKUP_RESTORED',
            'Backup',
            $filename,
            null,
            ['filename' => $filename, 'restored_counts' => $restoredCounts],
        );

        return [
            'filename' => $filename,
            'restored_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'restored_counts' => $restoredCounts,
        ];
    }

    /**
     * Check all FK relationships among restored tables.
     * Returns an array of human-readable violation descriptions (empty = all good).
     */
    private function validateForeignKeyIntegrity(Connection $connection, string $orgId, array $restoredTables): array
    {
        $violations = [];

        foreach (self::FK_CONSTRAINTS as [$childTable, $childColumn, $parentTable]) {
            // Only validate constraints where the child table was part of the restore.
            if (!in_array($childTable, $restoredTables, true)) {
                continue;
            }

            $sql = sprintf(
                'SELECT COUNT(*) FROM %s c LEFT JOIN %s p ON c.%s = p.id'
                . ' WHERE c.organization_id = :orgId AND c.%s IS NOT NULL AND p.id IS NULL',
                $connection->quoteIdentifier($childTable),
                $connection->quoteIdentifier($parentTable),
                $connection->quoteIdentifier($childColumn),
                $connection->quoteIdentifier($childColumn),
            );

            $orphanCount = (int) $connection->fetchOne($sql, ['orgId' => $orgId]);
            if ($orphanCount > 0) {
                $violations[] = sprintf(
                    '%s.%s → %s: %d orphaned row(s)',
                    $childTable,
                    $childColumn,
                    $parentTable,
                    $orphanCount,
                );
            }
        }

        return $violations;
    }

    /**
     * Validate that all enum-backed columns contain values from the allowed set.
     * Returns an array of human-readable violation descriptions (empty = all good).
     */
    private function validateEnumIntegrity(Connection $connection, string $orgId, array $restoredTables): array
    {
        $violations = [];

        foreach (self::ENUM_CONSTRAINTS as [$table, $column, $allowedValues]) {
            if (!in_array($table, $restoredTables, true)) {
                continue;
            }

            $placeholders = implode(',', array_map(fn ($v) => $connection->quote($v), $allowedValues));
            $sql = sprintf(
                'SELECT COUNT(*) FROM %s WHERE organization_id = :orgId AND %s NOT IN (%s)',
                $connection->quoteIdentifier($table),
                $connection->quoteIdentifier($column),
                $placeholders,
            );

            $invalidCount = (int) $connection->fetchOne($sql, ['orgId' => $orgId]);
            if ($invalidCount > 0) {
                $violations[] = sprintf(
                    '%s.%s: %d row(s) with invalid enum value (allowed: %s)',
                    $table,
                    $column,
                    $invalidCount,
                    implode(', ', $allowedValues),
                );
            }
        }

        return $violations;
    }

    /**
     * Resolve a backup filename to a full path, preventing path traversal attacks.
     * Only allows filenames matching the pattern: backup_{orgId}_{timestamp}.enc
     */
    private function resolveBackupPath(string $filename, string $orgId): string
    {
        // Strip any directory components — only a bare filename is allowed.
        $safe = basename($filename);

        // Enforce expected filename format to prevent arbitrary file access.
        if (!preg_match('/^backup_[a-zA-Z0-9\-]+_\d{8}_\d{6}\.enc$/', $safe)) {
            throw new \InvalidArgumentException('Invalid backup filename format');
        }

        $filepath = rtrim($this->backupStoragePath, '/') . '/' . $safe;

        if (!file_exists($filepath)) {
            throw new EntityNotFoundException('Backup', $safe);
        }

        // Confirm the resolved path is still inside the storage directory (realpath check).
        $realStorage = realpath($this->backupStoragePath);
        $realFile = realpath($filepath);
        if ($realStorage === false || $realFile === false || !str_starts_with($realFile, $realStorage . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Backup file path is outside storage directory');
        }

        return $filepath;
    }

    private function decryptAndDecode(string $encoded): array
    {
        $json = $this->decrypt($encoded);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Encrypt plaintext using AES-256-GCM (AEAD).
     *
     * Output format: base64( nonce || tag || ciphertext )
     *
     * - nonce: 12 bytes, randomly generated per encryption (NIST SP 800-38D)
     * - tag:   16 bytes, GCM authentication tag
     * - ciphertext: variable length
     */
    private function encrypt(string $plaintext): string
    {
        $nonce = openssl_random_pseudo_bytes(self::NONCE_LENGTH);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->backupEncryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',           // AAD (additional authenticated data) — empty
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * Decrypt ciphertext using AES-256-GCM (AEAD).
     *
     * GCM decryption automatically verifies the authentication tag.
     * If the nonce, ciphertext, or tag has been tampered with,
     * openssl_decrypt() returns false and we throw.
     */
    private function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new \RuntimeException('Invalid backup file encoding');
        }

        $minLength = self::NONCE_LENGTH + self::TAG_LENGTH;
        if (strlen($raw) < $minLength) {
            throw new \RuntimeException('Backup file is too short — likely corrupted');
        }

        $nonce      = substr($raw, 0, self::NONCE_LENGTH);
        $tag        = substr($raw, self::NONCE_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::NONCE_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->backupEncryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Backup integrity check failed — invalid key or corrupted/tampered data');
        }

        return $plaintext;
    }
}
