<?php

declare(strict_types=1);

namespace App\Enum;

enum BillType: string
{
    case INITIAL = 'initial';
    case RECURRING = 'recurring';
    case SUPPLEMENTAL = 'supplemental';
    case PENALTY = 'penalty';
}
