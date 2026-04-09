<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationStatus: string
{
    case PENDING = 'pending';
    case DELIVERED = 'delivered';
    case READ = 'read';

    public static function allowedTransitions(): array
    {
        return [
            self::PENDING->value => [self::DELIVERED],
            self::DELIVERED->value => [self::READ],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::allowedTransitions()[$this->value] ?? [];
        return in_array($target, $allowed, true);
    }

    public function isTerminal(): bool
    {
        return $this === self::READ;
    }
}
