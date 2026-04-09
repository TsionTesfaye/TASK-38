<?php

declare(strict_types=1);

namespace App\Exception;

class BillVoidException extends \DomainException
{
    public function __construct(string $reason, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Cannot void bill: {$reason}", $code, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function toArray(): array
    {
        return [
            'code' => $this->getHttpStatusCode(),
            'message' => $this->getMessage(),
            'details' => null,
        ];
    }
}
