<?php

declare(strict_types=1);

namespace App\Enum;

enum CapacityMode: string
{
    case DISCRETE_UNITS = 'discrete_units';
    case SINGLE_SLOT = 'single_slot';
}
