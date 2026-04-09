<?php

declare(strict_types=1);

namespace App\Enum;

enum RateType: string
{
    case HOURLY = 'hourly';
    case DAILY = 'daily';
    case MONTHLY = 'monthly';
    case FLAT = 'flat';
}
