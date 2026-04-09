<?php

declare(strict_types=1);

namespace App\Enum;

enum BillStatus: string
{
    case OPEN = 'open';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case VOIDED = 'voided';

    public function isTerminal(): bool
    {
        return $this === self::VOIDED;
    }

    public static function allowedTransitions(): array
    {
        return [
            self::OPEN->value => [self::PARTIALLY_PAID, self::PAID, self::VOIDED],
            self::PARTIALLY_PAID->value => [self::PAID, self::VOIDED],
            self::PAID->value => [self::PARTIALLY_REFUNDED],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::allowedTransitions()[$this->value] ?? [];
        return in_array($target, $allowed, true);
    }
}
