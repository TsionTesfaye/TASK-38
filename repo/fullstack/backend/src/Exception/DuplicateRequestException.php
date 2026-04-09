<?php

declare(strict_types=1);

namespace App\Exception;

class DuplicateRequestException extends \DomainException
{
    private array $previousResponse;

    public function __construct(array $previousResponse, int $code = 0, ?\Throwable $previous = null)
    {
        $this->previousResponse = $previousResponse;
        parent::__construct('Duplicate request', $code, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function getPreviousResponse(): array
    {
        return $this->previousResponse;
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
