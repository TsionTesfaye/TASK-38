<?php

declare(strict_types=1);

namespace App\Enum;

enum BookingStatus: string
{
    case CONFIRMED = 'confirmed';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case CANCELED = 'canceled';
    case NO_SHOW = 'no_show';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::CANCELED, self::NO_SHOW => true,
            default => false,
        };
    }

    public static function allowedTransitions(): array
    {
        return [
            self::CONFIRMED->value => [self::ACTIVE, self::CANCELED, self::NO_SHOW],
            self::ACTIVE->value => [self::COMPLETED, self::CANCELED, self::NO_SHOW],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::allowedTransitions()[$this->value] ?? [];
        return in_array($target, $allowed, true);
    }
}
