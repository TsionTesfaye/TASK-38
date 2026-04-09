<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Integration test: backup → restore with real FK-linked data.
 *
 * Inserts a full object graph (users, inventory, bookings, bills, payments,
 * refunds, ledger_entries) directly into the database, runs a backup/restore
 * cycle through BackupService, and verifies that:
 *   - row counts match before/after
 *   - FK constraints are satisfied
 *   - relationships are intact
 */
class BackupRestoreIntegrationTest extends KernelTestCase
{
    private Connection $conn;
    private string $orgId;
    private string $adminUserId;
    private string $tenantUserId;
    private string $itemId;
    private string $bookingId;
    private string $billId;
    private string $paymentId;
    private string $refundId;
    private string $ledgerEntryId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get('doctrine.dbal.default_connection');

        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        // Clean up test data in child → parent order.
        $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'ledger_entries', 'refunds', 'payments', 'bills', 'bookings',
            'booking_holds', 'inventory_pricing', 'inventory_items',
            'audit_logs', 'notifications', 'settings',
            'device_sessions', 'users', 'organizations',
        ] as $table) {
            $this->conn->executeStatement(
                "DELETE FROM {$table} WHERE "
                . ($table === 'organizations' ? 'id' : 'organization_id')
                . ' = :orgId',
                ['orgId' => $this->orgId],
            );
        }
        $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        parent::tearDown();
    }

    private function seedTestData(): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // ── Organization ──
        $this->orgId = Uuid::uuid4()->toString();
        $this->conn->insert('organizations', [
            'id' => $this->orgId,
            'code' => 'BKTEST_' . substr(Uuid::uuid4()->toString(), 0, 8),
            'name' => 'Backup Test Org',
            'is_active' => 1,
            'default_currency' => 'USD',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ── Users ──
        $this->adminUserId = Uuid::uuid4()->toString();
        $this->conn->insert('users', [
            'id' => $this->adminUserId,
            'organization_id' => $this->orgId,
            'username' => 'bkadmin_' . substr(Uuid::uuid4()->toString(), 0, 8),
            'password_hash' => password_hash('test', PASSWORD_BCRYPT),
            'display_name' => 'Backup Admin',
            'role' => 'administrator',
            'is_active' => 1,
            'is_frozen' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->tenantUserId = Uuid::uuid4()->toString();
        $this->conn->insert('users', [
            'id' => $this->tenantUserId,
            'organization_id' => $this->orgId,
            'username' => 'bktenant_' . substr(Uuid::uuid4()->toString(), 0, 8),
            'password_hash' => password_hash('test', PASSWORD_BCRYPT),
            'display_name' => 'Backup Tenant',
            'role' => 'tenant',
            'is_active' => 1,
            'is_frozen' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ── Inventory ──
        $this->itemId = Uuid::uuid4()->toString();
        $this->conn->insert('inventory_items', [
            'id' => $this->itemId,
            'organization_id' => $this->orgId,
            'asset_code' => 'BK-' . substr(Uuid::uuid4()->toString(), 0, 8),
            'name' => 'Backup Test Unit',
            'asset_type' => 'studio',
            'location_name' => 'Building A',
            'capacity_mode' => 'discrete_units',
            'total_capacity' => 1,
            'timezone' => 'UTC',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ── Booking ──
        $this->bookingId = Uuid::uuid4()->toString();
        $this->conn->insert('bookings', [
            'id' => $this->bookingId,
            'organization_id' => $this->orgId,
            'inventory_item_id' => $this->itemId,
            'tenant_user_id' => $this->tenantUserId,
            'status' => 'confirmed',
            'start_at' => '2099-01-01 09:00:00',
            'end_at' => '2099-01-02 09:00:00',
            'booked_units' => 1,
            'currency' => 'USD',
            'base_amount' => '100.00',
            'final_amount' => '100.00',
            'cancellation_fee_amount' => '0.00',
            'no_show_penalty_amount' => '0.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ── Bill ──
        $this->billId = Uuid::uuid4()->toString();
        $this->conn->insert('bills', [
            'id' => $this->billId,
            'organization_id' => $this->orgId,
            'booking_id' => $this->bookingId,
            'tenant_user_id' => $this->tenantUserId,
            'bill_type' => 'initial',
            'status' => 'paid',
            'currency' => 'USD',
            'original_amount' => '100.00',
            'outstanding_amount' => '0.00',
            'issued_at' => $now,
            'paid_at' => $now,
        ]);

        // ── Payment ──
        $this->paymentId = Uuid::uuid4()->toString();
        $this->conn->insert('payments', [
            'id' => $this->paymentId,
            'organization_id' => $this->orgId,
            'bill_id' => $this->billId,
            'request_id' => 'req_bk_' . Uuid::uuid4()->toString(),
            'status' => 'succeeded',
            'currency' => 'USD',
            'amount' => '100.00',
            'signature_verified' => 1,
            'received_at' => $now,
            'processed_at' => $now,
            'created_at' => $now,
        ]);

        // ── Refund ──
        $this->refundId = Uuid::uuid4()->toString();
        $this->conn->insert('refunds', [
            'id' => $this->refundId,
            'organization_id' => $this->orgId,
            'bill_id' => $this->billId,
            'payment_id' => $this->paymentId,
            'amount' => '25.00',
            'reason' => 'Partial refund for testing',
            'status' => 'issued',
            'created_by_user_id' => $this->adminUserId,
            'created_at' => $now,
        ]);

        // ── Ledger Entry ──
        $this->ledgerEntryId = Uuid::uuid4()->toString();
        $this->conn->insert('ledger_entries', [
            'id' => $this->ledgerEntryId,
            'organization_id' => $this->orgId,
            'booking_id' => $this->bookingId,
            'bill_id' => $this->billId,
            'payment_id' => $this->paymentId,
            'refund_id' => $this->refundId,
            'entry_type' => 'payment_received',
            'amount' => '100.00',
            'currency' => 'USD',
            'occurred_at' => $now,
        ]);
    }

    /**
     * Count rows for key tables belonging to this org.
     * @return array<string, int>
     */
    private function countOrgRows(): array
    {
        $tables = [
            'users', 'inventory_items', 'bookings',
            'bills', 'payments', 'refunds', 'ledger_entries',
        ];
        $counts = [];
        foreach ($tables as $t) {
            $counts[$t] = (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM {$t} WHERE organization_id = ?",
                [$this->orgId],
            );
        }
        return $counts;
    }

    // ─── Tests ────────────────────────────────────────────────────────

    public function testBackupAndRestorePreservesAllDataAndFkIntegrity(): void
    {
        $countsBefore = $this->countOrgRows();

        // Sanity: seed data is present.
        $this->assertSame(2, $countsBefore['users']);
        $this->assertSame(1, $countsBefore['inventory_items']);
        $this->assertSame(1, $countsBefore['bookings']);
        $this->assertSame(1, $countsBefore['bills']);
        $this->assertSame(1, $countsBefore['payments']);
        $this->assertSame(1, $countsBefore['refunds']);
        $this->assertSame(1, $countsBefore['ledger_entries']);

        // ── Create backup via service ──
        $service = self::getContainer()->get('App\Service\BackupService');

        $adminUser = self::getContainer()->get('doctrine')
            ->getRepository(\App\Entity\User::class)
            ->find($this->adminUserId);
        $this->assertNotNull($adminUser, 'Admin user must exist in DB');

        $backup = $service->createBackup($adminUser);
        $this->assertStringStartsWith('backup_', $backup['filename']);

        // ── Restore ──
        $result = $service->restore($adminUser, $backup['filename']);
        $this->assertSame($backup['filename'], $result['filename']);
        $this->assertArrayHasKey('restored_counts', $result);

        // ── Assert row counts match ──
        $countsAfter = $this->countOrgRows();
        $this->assertSame($countsBefore, $countsAfter, 'Row counts must match after restore');

        // ── Assert FK constraints satisfied ──
        // payments → bills
        $orphanPayments = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM payments p LEFT JOIN bills b ON p.bill_id = b.id'
            . ' WHERE p.organization_id = ? AND b.id IS NULL',
            [$this->orgId],
        );
        $this->assertSame(0, $orphanPayments, 'payments.bill_id FK must be satisfied');

        // refunds → bills
        $orphanRefundsBill = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM refunds r LEFT JOIN bills b ON r.bill_id = b.id'
            . ' WHERE r.organization_id = ? AND b.id IS NULL',
            [$this->orgId],
        );
        $this->assertSame(0, $orphanRefundsBill, 'refunds.bill_id FK must be satisfied');

        // refunds → payments
        $orphanRefundsPay = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM refunds r LEFT JOIN payments p ON r.payment_id = p.id'
            . ' WHERE r.organization_id = ? AND r.payment_id IS NOT NULL AND p.id IS NULL',
            [$this->orgId],
        );
        $this->assertSame(0, $orphanRefundsPay, 'refunds.payment_id FK must be satisfied');

        // ledger_entries → bills
        $orphanLedgerBill = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries l LEFT JOIN bills b ON l.bill_id = b.id'
            . ' WHERE l.organization_id = ? AND l.bill_id IS NOT NULL AND b.id IS NULL',
            [$this->orgId],
        );
        $this->assertSame(0, $orphanLedgerBill, 'ledger_entries.bill_id FK must be satisfied');

        // ledger_entries → payments
        $orphanLedgerPay = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries l LEFT JOIN payments p ON l.payment_id = p.id'
            . ' WHERE l.organization_id = ? AND l.payment_id IS NOT NULL AND p.id IS NULL',
            [$this->orgId],
        );
        $this->assertSame(0, $orphanLedgerPay, 'ledger_entries.payment_id FK must be satisfied');

        // ledger_entries → refunds
        $orphanLedgerRef = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ledger_entries l LEFT JOIN refunds r ON l.refund_id = r.id'
            . ' WHERE l.organization_id = ? AND l.refund_id IS NOT NULL AND r.id IS NULL',
            [$this->orgId],
        );
        $this->assertSame(0, $orphanLedgerRef, 'ledger_entries.refund_id FK must be satisfied');

        // ── Assert relationships are intact ──
        $payment = $this->conn->fetchAssociative(
            'SELECT * FROM payments WHERE id = ?',
            [$this->paymentId],
        );
        $this->assertSame($this->billId, $payment['bill_id'], 'Payment must reference correct bill');

        $refund = $this->conn->fetchAssociative(
            'SELECT * FROM refunds WHERE id = ?',
            [$this->refundId],
        );
        $this->assertSame($this->billId, $refund['bill_id'], 'Refund must reference correct bill');
        $this->assertSame($this->paymentId, $refund['payment_id'], 'Refund must reference correct payment');

        $ledger = $this->conn->fetchAssociative(
            'SELECT * FROM ledger_entries WHERE id = ?',
            [$this->ledgerEntryId],
        );
        $this->assertSame($this->billId, $ledger['bill_id'], 'Ledger must reference correct bill');
        $this->assertSame($this->paymentId, $ledger['payment_id'], 'Ledger must reference correct payment');
        $this->assertSame($this->refundId, $ledger['refund_id'], 'Ledger must reference correct refund');
        $this->assertSame($this->bookingId, $ledger['booking_id'], 'Ledger must reference correct booking');

        // ── Assert enum values are valid after restore ──
        $bill = $this->conn->fetchAssociative('SELECT * FROM bills WHERE id = ?', [$this->billId]);
        $this->assertContains($bill['bill_type'], ['initial', 'recurring', 'supplemental', 'penalty'], 'Bill type must be valid BillType enum');
        $this->assertContains($bill['status'], ['open', 'partially_paid', 'paid', 'partially_refunded', 'voided'], 'Bill status must be valid BillStatus enum');

        $this->assertContains($payment['status'], ['pending', 'succeeded', 'failed', 'rejected'], 'Payment status must be valid PaymentStatus enum');

        $this->assertContains($refund['status'], ['issued', 'rejected'], 'Refund status must be valid RefundStatus enum');

        $this->assertContains($ledger['entry_type'], ['bill_issued', 'payment_received', 'refund_issued', 'penalty_applied', 'bill_voided'], 'Ledger entry type must be valid LedgerEntryType enum');
    }

    public function testRestoreIsAtomicOnFailure(): void
    {
        $countsBefore = $this->countOrgRows();

        $service = self::getContainer()->get('App\Service\BackupService');
        $adminUser = self::getContainer()->get('doctrine')
            ->getRepository(\App\Entity\User::class)
            ->find($this->adminUserId);

        $backup = $service->createBackup($adminUser);

        // Corrupt the backup file: find it via the BACKUP_STORAGE_PATH env var.
        $storagePath = $_ENV['BACKUP_STORAGE_PATH'] ?? $_SERVER['BACKUP_STORAGE_PATH'] ?? null;
        if ($storagePath === null) {
            $this->markTestSkipped('BACKUP_STORAGE_PATH env var not set');
        }
        $filepath = rtrim($storagePath, '/') . '/' . $backup['filename'];
        file_put_contents($filepath, 'not-valid-base64!!!');

        try {
            $service->restore($adminUser, $backup['filename']);
            $this->fail('Restore of corrupted backup should throw');
        } catch (\Throwable) {
            // Expected.
        }

        // Data must be untouched — no partial restore.
        $countsAfter = $this->countOrgRows();
        $this->assertSame($countsBefore, $countsAfter, 'Failed restore must not alter data');
    }
}
