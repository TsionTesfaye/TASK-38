<?php

declare(strict_types=1);

namespace App\Enum;

enum SessionStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case REVOKED = 'revoked';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::EXPIRED, self::REVOKED => true,
            default => false,
        };
    }

    public static function allowedTransitions(): array
    {
        return [
            self::ACTIVE->value => [self::EXPIRED, self::REVOKED],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::allowedTransitions()[$this->value] ?? [];
        return in_array($target, $allowed, true);
    }
}
