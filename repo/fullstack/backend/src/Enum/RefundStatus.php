<?php

declare(strict_types=1);

namespace App\Enum;

enum RefundStatus: string
{
    case ISSUED = 'issued';
    case REJECTED = 'rejected';
}
