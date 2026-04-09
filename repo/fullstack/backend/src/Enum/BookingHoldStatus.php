<?php

declare(strict_types=1);

namespace App\Enum;

enum BookingHoldStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case RELEASED = 'released';
    case CONVERTED = 'converted';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::EXPIRED, self::RELEASED, self::CONVERTED => true,
            default => false,
        };
    }

    public static function allowedTransitions(): array
    {
        return [
            self::ACTIVE->value => [self::EXPIRED, self::RELEASED, self::CONVERTED],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::allowedTransitions()[$this->value] ?? [];
        return in_array($target, $allowed, true);
    }
}
