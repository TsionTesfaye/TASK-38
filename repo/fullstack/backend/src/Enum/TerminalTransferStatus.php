<?php

declare(strict_types=1);

namespace App\Enum;

enum TerminalTransferStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case PAUSED = 'paused';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED => true,
            default => false,
        };
    }

    public static function allowedTransitions(): array
    {
        return [
            self::PENDING->value => [self::IN_PROGRESS, self::FAILED],
            self::IN_PROGRESS->value => [self::PAUSED, self::COMPLETED, self::FAILED],
            self::PAUSED->value => [self::IN_PROGRESS, self::FAILED],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::allowedTransitions()[$this->value] ?? [];
        return in_array($target, $allowed, true);
    }
}
