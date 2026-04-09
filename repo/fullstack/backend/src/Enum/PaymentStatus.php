<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case REJECTED = 'rejected';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::SUCCEEDED, self::FAILED, self::REJECTED => true,
            default => false,
        };
    }

    public static function allowedTransitions(): array
    {
        return [
            self::PENDING->value => [self::SUCCEEDED, self::FAILED, self::REJECTED],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::allowedTransitions()[$this->value] ?? [];
        return in_array($target, $allowed, true);
    }
}
