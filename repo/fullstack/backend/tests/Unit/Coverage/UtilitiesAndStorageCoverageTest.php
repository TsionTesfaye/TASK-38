<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Audit\AuditActions;
use App\Enum\BillStatus;
use App\Enum\CapacityMode;
use App\Enum\UserRole;
use App\Exception\InvalidEnumException;
use App\Storage\LocalStorageService;
use App\Validation\EnumValidator;
use PHPUnit\Framework\TestCase;

class UtilitiesAndStorageCoverageTest extends TestCase
{
    public function testAuditActionsConstants(): void
    {
        // Reference every constant — ensures the class is loaded.
        $this->assertSame('auth.login', AuditActions::AUTH_LOGIN);
        $this->assertSame('auth.logout', AuditActions::AUTH_LOGOUT);
        $this->assertSame('auth.refresh', AuditActions::AUTH_REFRESH);
        $this->assertSame('auth.password_change', AuditActions::AUTH_PASSWORD_CHANGE);
        $this->assertSame('auth.bootstrap', AuditActions::AUTH_BOOTSTRAP);
        $this->assertSame('user.created', AuditActions::USER_CREATED);
        $this->assertSame('user.updated', AuditActions::USER_UPDATED);
        $this->assertSame('user.frozen', AuditActions::USER_FROZEN);
        $this->assertSame('user.unfrozen', AuditActions::USER_UNFROZEN);
        $this->assertSame('inventory.created', AuditActions::INVENTORY_CREATED);
        $this->assertSame('inventory.updated', AuditActions::INVENTORY_UPDATED);
        $this->assertSame('inventory.deactivated', AuditActions::INVENTORY_DEACTIVATED);
        $this->assertSame('pricing.created', AuditActions::PRICING_CREATED);
        $this->assertSame('hold.created', AuditActions::HOLD_CREATED);
        $this->assertSame('hold.confirmed', AuditActions::HOLD_CONFIRMED);
        $this->assertSame('hold.released', AuditActions::HOLD_RELEASED);
        $this->assertSame('hold.expired', AuditActions::HOLD_EXPIRED);
        $this->assertSame('booking.created', AuditActions::BOOKING_CREATED);
        $this->assertSame('booking.checked_in', AuditActions::BOOKING_CHECKED_IN);
        $this->assertSame('booking.completed', AuditActions::BOOKING_COMPLETED);
        $this->assertSame('booking.canceled', AuditActions::BOOKING_CANCELED);
        $this->assertSame('booking.no_show', AuditActions::BOOKING_NO_SHOW);
        $this->assertSame('booking.rescheduled', AuditActions::BOOKING_RESCHEDULED);
        $this->assertSame('bill.issued', AuditActions::BILL_ISSUED);
        $this->assertSame('bill.voided', AuditActions::BILL_VOIDED);
        $this->assertSame('payment.initiated', AuditActions::PAYMENT_INITIATED);
        $this->assertSame('payment.callback_processed', AuditActions::PAYMENT_CALLBACK_PROCESSED);
        $this->assertSame('refund.issued', AuditActions::REFUND_ISSUED);
        $this->assertSame('reconciliation.run', AuditActions::RECONCILIATION_RUN);
        $this->assertSame('export.generated', AuditActions::EXPORT_GENERATED);
        $this->assertSame('settings.updated', AuditActions::SETTINGS_UPDATED);
        $this->assertSame('terminal.registered', AuditActions::TERMINAL_REGISTERED);
        $this->assertSame('terminal.updated', AuditActions::TERMINAL_UPDATED);
        $this->assertSame('transfer.initiated', AuditActions::TRANSFER_INITIATED);
        $this->assertSame('transfer.completed', AuditActions::TRANSFER_COMPLETED);
        $this->assertSame('backup.created', AuditActions::BACKUP_CREATED);
        $this->assertSame('restore.executed', AuditActions::RESTORE_EXECUTED);
    }

    public function testEnumValidatorValidValue(): void
    {
        $role = EnumValidator::validate('tenant', UserRole::class, 'role');
        $this->assertSame(UserRole::TENANT, $role);

        $mode = EnumValidator::validate('discrete_units', CapacityMode::class, 'capacity_mode');
        $this->assertSame(CapacityMode::DISCRETE_UNITS, $mode);

        $status = EnumValidator::validate('open', BillStatus::class, 'status');
        $this->assertSame(BillStatus::OPEN, $status);
    }

    public function testEnumValidatorInvalidValue(): void
    {
        try {
            EnumValidator::validate('not_a_role', UserRole::class, 'role');
            $this->fail('Expected InvalidEnumException');
        } catch (InvalidEnumException $e) {
            $this->assertSame(422, $e->getHttpStatusCode());
            $arr = $e->toArray();
            $this->assertSame('role', $arr['field']);
            $this->assertContains('tenant', $arr['allowed_values']);
            $this->assertContains('administrator', $arr['allowed_values']);
        }
    }

    public function testLocalStorageServiceRoundTrip(): void
    {
        $tmp = sys_get_temp_dir() . '/storage_test_' . uniqid();
        mkdir($tmp, 0750, true);

        try {
            $_ENV['STORAGE_PATH'] = $tmp;
            $s = new LocalStorageService();

            $path = $s->storePdf('PDF CONTENT', 'test.pdf');
            $this->assertStringContainsString('pdfs/test.pdf', $path);
            $this->assertTrue($s->fileExists($path));

            $content = $s->getFile($path);
            $this->assertSame('PDF CONTENT', $content);

            $exportPath = $s->storeExport('CSV', 'export.csv');
            $this->assertStringContainsString('exports/export.csv', $exportPath);

            $backupPath = $s->storeBackup('backup data', 'backup.enc');
            $this->assertStringContainsString('backups/backup.enc', $backupPath);

            $assetPath = $s->storeTerminalAsset('asset', 'asset.bin');
            $this->assertStringContainsString('terminal_assets/asset.bin', $assetPath);

            $listing = $s->listDirectory('pdfs');
            $this->assertContains('test.pdf', $listing);

            $empty = $s->listDirectory('nonexistent_dir');
            $this->assertSame([], $empty);

            $s->deleteFile($path);
            $this->assertFalse($s->fileExists($path));

            // Delete nonexistent — should not throw
            $s->deleteFile('/does/not/exist');
            $this->addToAssertionCount(1);
        } finally {
            // Recursive cleanup
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tmp, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            @rmdir($tmp);
        }
    }

    public function testLocalStorageServiceGetFileNotFound(): void
    {
        $tmp = sys_get_temp_dir() . '/storage_nf_' . uniqid();
        mkdir($tmp, 0750, true);
        try {
            $_ENV['STORAGE_PATH'] = $tmp;
            $s = new LocalStorageService();
            $this->expectException(\RuntimeException::class);
            $s->getFile('does-not-exist');
        } finally {
            @rmdir($tmp);
        }
    }
}
