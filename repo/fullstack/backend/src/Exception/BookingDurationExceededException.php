<?php

declare(strict_types=1);

namespace App\Exception;

class BookingDurationExceededException extends \DomainException
{
    public function __construct(string $message = 'Booking duration exceeds maximum allowed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
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
