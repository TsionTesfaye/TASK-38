<?php

declare(strict_types=1);

namespace App\Exception;

class InsufficientCapacityException extends \DomainException
{
    public function __construct(string $message = 'Insufficient inventory capacity', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
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
