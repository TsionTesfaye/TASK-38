<?php

declare(strict_types=1);

namespace App\Exception;

class AccountFrozenException extends AuthenticationException
{
    public function __construct(string $message = 'Account is frozen', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return 403;
    }
}
