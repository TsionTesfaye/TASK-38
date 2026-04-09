<?php

declare(strict_types=1);

namespace App\Exception;

class OrganizationScopeMismatchException extends AccessDeniedException
{
    public function __construct(string $message = 'Organization scope mismatch', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
