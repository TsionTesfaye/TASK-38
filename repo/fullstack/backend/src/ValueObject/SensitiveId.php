<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Centralized ID masking utility. Shows only last 4 characters.
 * Used across API responses, logs, exports, and PDFs.
 */
final class SensitiveId
{
    public static function mask(string $id): string
    {
        if (strlen($id) <= 4) {
            return $id;
        }
        return str_repeat('*', strlen($id) - 4) . substr($id, -4);
    }
}
