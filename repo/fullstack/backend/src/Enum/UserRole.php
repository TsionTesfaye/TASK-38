<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case ADMINISTRATOR = 'administrator';
    case PROPERTY_MANAGER = 'property_manager';
    case TENANT = 'tenant';
    case FINANCE_CLERK = 'finance_clerk';
}
