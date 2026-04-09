<?php

declare(strict_types=1);

namespace App\Enum;

enum LedgerEntryType: string
{
    case BILL_ISSUED = 'bill_issued';
    case PAYMENT_RECEIVED = 'payment_received';
    case REFUND_ISSUED = 'refund_issued';
    case PENALTY_APPLIED = 'penalty_applied';
    case BILL_VOIDED = 'bill_voided';
}
