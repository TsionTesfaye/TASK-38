<?php

declare(strict_types=1);

namespace App\Enum;

enum BookingEventType: string
{
    case CREATED = 'created';
    case HOLD_CONVERTED = 'hold_converted';
    case RESCHEDULED = 'rescheduled';
    case ACTIVATED = 'activated';
    case COMPLETED = 'completed';
    case CANCELED = 'canceled';
    case NO_SHOW_MARKED = 'no_show_marked';
    case CANCELLATION_FEE_APPLIED = 'cancellation_fee_applied';
    case NO_SHOW_PENALTY_APPLIED = 'no_show_penalty_applied';
}
