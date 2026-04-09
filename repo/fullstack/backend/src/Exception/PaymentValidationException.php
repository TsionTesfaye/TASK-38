<?php

declare(strict_types=1);

namespace App\Exception;

class PaymentValidationException extends \DomainException
{
    public function __construct(string $reason, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Payment validation failed: {$reason}", $code, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return 422;
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
