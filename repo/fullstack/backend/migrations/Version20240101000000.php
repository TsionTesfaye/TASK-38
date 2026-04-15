<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create all 21 application tables';
    }

    public function up(Schema $schema): void
    {
        // 1. organizations
        $this->addSql('CREATE TABLE organizations (
            id CHAR(36) NOT NULL,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            default_currency CHAR(3) NOT NULL DEFAULT \'USD\',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_organizations_code (code),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 2. users
        $this->addSql('CREATE TABLE users (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            username VARCHAR(180) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            role VARCHAR(30) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_frozen TINYINT(1) NOT NULL DEFAULT 0,
            password_changed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_users_username (username),
            INDEX IDX_users_organization_id (organization_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_users_organization FOREIGN KEY (organization_id) REFERENCES organizations (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 3. device_sessions
        $this->addSql('CREATE TABLE device_sessions (
            id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            refresh_token_hash VARCHAR(255) NOT NULL,
            device_label VARCHAR(255) NOT NULL,
            client_device_id VARCHAR(255) NOT NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            revoked_at DATETIME DEFAULT NULL,
            INDEX IDX_device_sessions_user_id (user_id),
            INDEX IDX_device_sessions_user_revoked (user_id, revoked_at),
            PRIMARY KEY(id),
            CONSTRAINT FK_device_sessions_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 4. settings
        $this->addSql('CREATE TABLE settings (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            timezone VARCHAR(100) NOT NULL DEFAULT \'UTC\',
            allow_partial_payments TINYINT(1) NOT NULL DEFAULT 0,
            cancellation_fee_pct DECIMAL(5,2) NOT NULL DEFAULT 20.00,
            no_show_fee_pct DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            no_show_first_day_rent_enabled TINYINT(1) NOT NULL DEFAULT 1,
            hold_duration_minutes INT NOT NULL DEFAULT 10,
            no_show_grace_period_minutes INT NOT NULL DEFAULT 30,
            max_devices_per_user INT NOT NULL DEFAULT 5,
            recurring_bill_day INT NOT NULL DEFAULT 1,
            recurring_bill_hour INT NOT NULL DEFAULT 9,
            booking_attempts_per_item_per_minute INT NOT NULL DEFAULT 30,
            max_booking_duration_days INT NOT NULL DEFAULT 365,
            terminals_enabled TINYINT(1) NOT NULL DEFAULT 0,
            notification_templates JSON NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_settings_organization_id (organization_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_settings_organization FOREIGN KEY (organization_id) REFERENCES organizations (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 5. inventory_items
        $this->addSql('CREATE TABLE inventory_items (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            asset_code VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            asset_type VARCHAR(100) NOT NULL,
            location_name VARCHAR(255) NOT NULL,
            capacity_mode VARCHAR(30) NOT NULL,
            total_capacity INT NOT NULL,
            timezone VARCHAR(100) NOT NULL DEFAULT \'UTC\',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_inventory_items_org_asset (organization_id, asset_code),
            INDEX IDX_inventory_items_organization_id (organization_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_inventory_items_organization FOREIGN KEY (organization_id) REFERENCES organizations (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 6. inventory_pricing
        $this->addSql('CREATE TABLE inventory_pricing (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            inventory_item_id CHAR(36) NOT NULL,
            rate_type VARCHAR(30) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency CHAR(3) NOT NULL,
            effective_from DATETIME NOT NULL,
            effective_to DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_inventory_pricing_item_effective (inventory_item_id, effective_from),
            PRIMARY KEY(id),
            CONSTRAINT FK_inventory_pricing_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
            CONSTRAINT FK_inventory_pricing_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 7. booking_holds
        $this->addSql('CREATE TABLE booking_holds (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            inventory_item_id CHAR(36) NOT NULL,
            tenant_user_id CHAR(36) NOT NULL,
            request_key VARCHAR(255) NOT NULL,
            held_units INT NOT NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'active\',
            created_at DATETIME NOT NULL,
            confirmed_booking_id CHAR(36) DEFAULT NULL,
            UNIQUE INDEX UNIQ_booking_holds_tenant_request (tenant_user_id, request_key),
            INDEX IDX_booking_holds_item_status (inventory_item_id, status),
            INDEX IDX_booking_holds_expires_status (expires_at, status),
            PRIMARY KEY(id),
            CONSTRAINT FK_booking_holds_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
            CONSTRAINT FK_booking_holds_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items (id),
            CONSTRAINT FK_booking_holds_tenant FOREIGN KEY (tenant_user_id) REFERENCES users (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 8. bookings
        $this->addSql('CREATE TABLE bookings (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            inventory_item_id CHAR(36) NOT NULL,
            tenant_user_id CHAR(36) NOT NULL,
            source_hold_id CHAR(36) DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'confirmed\',
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            booked_units INT NOT NULL,
            currency CHAR(3) NOT NULL,
            base_amount DECIMAL(12,2) NOT NULL,
            final_amount DECIMAL(12,2) NOT NULL,
            cancellation_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            no_show_penalty_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            canceled_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            no_show_marked_at DATETIME DEFAULT NULL,
            checked_in_at DATETIME DEFAULT NULL,
            INDEX IDX_bookings_org_tenant (organization_id, tenant_user_id),
            INDEX IDX_bookings_item_status_dates (inventory_item_id, status, start_at, end_at),
            PRIMARY KEY(id),
            CONSTRAINT FK_bookings_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
            CONSTRAINT FK_bookings_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items (id),
            CONSTRAINT FK_bookings_tenant FOREIGN KEY (tenant_user_id) REFERENCES users (id),
            CONSTRAINT FK_bookings_hold FOREIGN KEY (source_hold_id) REFERENCES booking_holds (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 9. booking_events
        $this->addSql('CREATE TABLE booking_events (
            id CHAR(36) NOT NULL,
            booking_id CHAR(36) NOT NULL,
            actor_user_id CHAR(36) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            before_status VARCHAR(30) DEFAULT NULL,
            after_status VARCHAR(30) DEFAULT NULL,
            details_json JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_booking_events_booking_id (booking_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_booking_events_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
            CONSTRAINT FK_booking_events_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 10. bills
        $this->addSql('CREATE TABLE bills (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            booking_id CHAR(36) DEFAULT NULL,
            tenant_user_id CHAR(36) NOT NULL,
            bill_type VARCHAR(30) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'open\',
            currency CHAR(3) NOT NULL,
            original_amount DECIMAL(12,2) NOT NULL,
            outstanding_amount DECIMAL(12,2) NOT NULL,
            due_at DATETIME DEFAULT NULL,
            issued_at DATETIME NOT NULL,
            paid_at DATETIME DEFAULT NULL,
            voided_at DATETIME DEFAULT NULL,
            pdf_path VARCHAR(500) DEFAULT NULL,
            INDEX IDX_bills_org_tenant (organization_id, tenant_user_id),
            INDEX IDX_bills_org_status (organization_id, status),
            PRIMARY KEY(id),
            CONSTRAINT FK_bills_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
            CONSTRAINT FK_bills_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
            CONSTRAINT FK_bills_tenant FOREIGN KEY (tenant_user_id) REFERENCES users (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 11. payments
        $this->addSql('CREATE TABLE payments (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            bill_id CHAR(36) NOT NULL,
            external_reference VARCHAR(255) DEFAULT NULL,
            request_id VARCHAR(255) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'pending\',
            currency CHAR(3) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            signature_verified TINYINT(1) NOT NULL DEFAULT 0,
            received_at DATETIME NOT NULL,
            processed_at DATETIME DEFAULT NULL,
            raw_callback_payload_json JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_payments_request_id (request_id),
            INDEX IDX_payments_bill_status (bill_id, status),
            PRIMARY KEY(id),
            CONSTRAINT FK_payments_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
            CONSTRAINT FK_payments_bill FOREIGN KEY (bill_id) REFERENCES bills (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 12. refunds
        $this->addSql('CREATE TABLE refunds (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            bill_id CHAR(36) NOT NULL,
            payment_id CHAR(36) DEFAULT NULL,
            amount DECIMAL(12,2) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            status VARCHAR(30) NOT NULL,
            created_by_user_id CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_refunds_bill_id (bill_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_refunds_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
            CONSTRAINT FK_refunds_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
            CONSTRAINT FK_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
            CONSTRAINT FK_refunds_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 13. ledger_entries
        $this->addSql('CREATE TABLE ledger_entries (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            booking_id CHAR(36) DEFAULT NULL,
            bill_id CHAR(36) DEFAULT NULL,
            payment_id CHAR(36) DEFAULT NULL,
            refund_id CHAR(36) DEFAULT NULL,
            entry_type VARCHAR(30) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency CHAR(3) NOT NULL,
            occurred_at DATETIME NOT NULL,
            metadata_json JSON DEFAULT NULL,
            INDEX IDX_ledger_entries_org_occurred (organization_id, occurred_at),
            INDEX IDX_ledger_entries_bill_id (bill_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_ledger_entries_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
            CONSTRAINT FK_ledger_entries_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
            CONSTRAINT FK_ledger_entries_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
            CONSTRAINT FK_ledger_entries_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
            CONSTRAINT FK_ledger_entries_refund FOREIGN KEY (refund_id) REFERENCES refunds (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 14. notification_preferences
        $this->addSql('CREATE TABLE notification_preferences (
            id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            event_code VARCHAR(100) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            dnd_start_local VARCHAR(5) NOT NULL DEFAULT \'21:00\',
            dnd_end_local VARCHAR(5) NOT NULL DEFAULT \'08:00\',
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_notification_prefs_user_event (user_id, event_code),
            PRIMARY KEY(id),
            CONSTRAINT FK_notification_prefs_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 15. notifications
        $this->addSql('CREATE TABLE notifications (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            event_code VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'pending\',
            scheduled_for DATETIME NOT NULL,
            delivered_at DATETIME DEFAULT NULL,
            read_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_notifications_user_status (user_id, status),
            PRIMARY KEY(id),
            CONSTRAINT FK_notifications_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
            CONSTRAINT FK_notifications_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 16. terminals
        $this->addSql('CREATE TABLE terminals (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            terminal_code VARCHAR(100) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            location_group VARCHAR(255) NOT NULL,
            language_code VARCHAR(10) NOT NULL DEFAULT \'en\',
            accessibility_mode TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_sync_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_terminals_org_code (organization_id, terminal_code),
            PRIMARY KEY(id),
            CONSTRAINT FK_terminals_organization FOREIGN KEY (organization_id) REFERENCES organizations (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 17. terminal_playlists
        $this->addSql('CREATE TABLE terminal_playlists (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            name VARCHAR(255) NOT NULL,
            location_group VARCHAR(255) NOT NULL,
            schedule_rule VARCHAR(500) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX IDX_terminal_playlists_org_location (organization_id, location_group),
            PRIMARY KEY(id),
            CONSTRAINT FK_terminal_playlists_organization FOREIGN KEY (organization_id) REFERENCES organizations (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 18. terminal_package_transfers
        $this->addSql('CREATE TABLE terminal_package_transfers (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            terminal_id CHAR(36) NOT NULL,
            package_name VARCHAR(255) NOT NULL,
            checksum VARCHAR(255) NOT NULL,
            total_chunks INT NOT NULL,
            transferred_chunks INT NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT \'pending\',
            started_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            INDEX IDX_terminal_pkg_transfers_terminal_status (terminal_id, status),
            PRIMARY KEY(id),
            CONSTRAINT FK_terminal_pkg_transfers_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
            CONSTRAINT FK_terminal_pkg_transfers_terminal FOREIGN KEY (terminal_id) REFERENCES terminals (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 19. reconciliation_runs
        $this->addSql('CREATE TABLE reconciliation_runs (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            run_date DATE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'running\',
            mismatch_count INT NOT NULL DEFAULT 0,
            output_csv_path VARCHAR(500) DEFAULT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_reconciliation_runs_org_date (organization_id, run_date),
            PRIMARY KEY(id),
            CONSTRAINT FK_reconciliation_runs_organization FOREIGN KEY (organization_id) REFERENCES organizations (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 20. audit_logs
        $this->addSql('CREATE TABLE audit_logs (
            id CHAR(36) NOT NULL,
            organization_id CHAR(36) NOT NULL,
            actor_user_id CHAR(36) DEFAULT NULL,
            actor_username_snapshot VARCHAR(180) NOT NULL,
            action_code VARCHAR(100) NOT NULL,
            object_type VARCHAR(100) NOT NULL,
            object_id VARCHAR(255) NOT NULL,
            before_json JSON DEFAULT NULL,
            after_json JSON DEFAULT NULL,
            client_device_id VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_audit_logs_org_created (organization_id, created_at),
            INDEX IDX_audit_logs_object (object_type, object_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_audit_logs_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
            CONSTRAINT FK_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // 21. idempotency_keys
        $this->addSql('CREATE TABLE idempotency_keys (
            id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            request_key VARCHAR(255) NOT NULL,
            response_payload_json JSON NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_idempotency_keys_user_request (user_id, request_key),
            INDEX IDX_idempotency_keys_expires_at (expires_at),
            PRIMARY KEY(id),
            CONSTRAINT FK_idempotency_keys_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idempotency_keys');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE reconciliation_runs');
        $this->addSql('DROP TABLE terminal_package_transfers');
        $this->addSql('DROP TABLE terminal_playlists');
        $this->addSql('DROP TABLE terminals');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('DROP TABLE notification_preferences');
        $this->addSql('DROP TABLE ledger_entries');
        $this->addSql('DROP TABLE refunds');
        $this->addSql('DROP TABLE payments');
        $this->addSql('DROP TABLE bills');
        $this->addSql('DROP TABLE booking_events');
        $this->addSql('DROP TABLE bookings');
        $this->addSql('DROP TABLE booking_holds');
        $this->addSql('DROP TABLE inventory_pricing');
        $this->addSql('DROP TABLE inventory_items');
        $this->addSql('DROP TABLE settings');
        $this->addSql('DROP TABLE device_sessions');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE organizations');
    }
}
