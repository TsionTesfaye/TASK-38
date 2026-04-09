<?php

declare(strict_types=1);

namespace App\Enum;

enum ReconciliationRunStatus: string
{
    case RUNNING = 'running';
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
            self::RUNNING->value => [self::COMPLETED, self::FAILED],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::allowedTransitions()[$this->value] ?? [];
        return in_array($target, $allowed, true);
    }
}
