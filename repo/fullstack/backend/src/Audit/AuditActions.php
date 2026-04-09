<?php

declare(strict_types=1);

namespace App\Audit;

final class AuditActions
{
    // Authentication
    public const AUTH_LOGIN = 'auth.login';
    public const AUTH_LOGOUT = 'auth.logout';
    public const AUTH_REFRESH = 'auth.refresh';
    public const AUTH_PASSWORD_CHANGE = 'auth.password_change';
    public const AUTH_BOOTSTRAP = 'auth.bootstrap';

    // User management
    public const USER_CREATED = 'user.created';
    public const USER_UPDATED = 'user.updated';
    public const USER_FROZEN = 'user.frozen';
    public const USER_UNFROZEN = 'user.unfrozen';

    // Inventory
    public const INVENTORY_CREATED = 'inventory.created';
    public const INVENTORY_UPDATED = 'inventory.updated';
    public const INVENTORY_DEACTIVATED = 'inventory.deactivated';

    // Pricing
    public const PRICING_CREATED = 'pricing.created';

    // Holds
    public const HOLD_CREATED = 'hold.created';
    public const HOLD_CONFIRMED = 'hold.confirmed';
    public const HOLD_RELEASED = 'hold.released';
    public const HOLD_EXPIRED = 'hold.expired';

    // Bookings
    public const BOOKING_CREATED = 'booking.created';
    public const BOOKING_CHECKED_IN = 'booking.checked_in';
    public const BOOKING_COMPLETED = 'booking.completed';
    public const BOOKING_CANCELED = 'booking.canceled';
    public const BOOKING_NO_SHOW = 'booking.no_show';
    public const BOOKING_RESCHEDULED = 'booking.rescheduled';

    // Billing
    public const BILL_ISSUED = 'bill.issued';
    public const BILL_VOIDED = 'bill.voided';

    // Payments
    public const PAYMENT_INITIATED = 'payment.initiated';
    public const PAYMENT_CALLBACK_PROCESSED = 'payment.callback_processed';

    // Refunds
    public const REFUND_ISSUED = 'refund.issued';

    // Reconciliation
    public const RECONCILIATION_RUN = 'reconciliation.run';

    // Exports
    public const EXPORT_GENERATED = 'export.generated';

    // Settings
    public const SETTINGS_UPDATED = 'settings.updated';

    // Terminals and transfers
    public const TERMINAL_REGISTERED = 'terminal.registered';
    public const TERMINAL_UPDATED = 'terminal.updated';
    public const TRANSFER_INITIATED = 'transfer.initiated';
    public const TRANSFER_COMPLETED = 'transfer.completed';

    // Backup and restore
    public const BACKUP_CREATED = 'backup.created';
    public const RESTORE_EXECUTED = 'restore.executed';

    private function __construct()
    {
        // Prevent instantiation
    }
}
