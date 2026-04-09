<?php

declare(strict_types=1);

namespace App\Exception;

class InvalidStateTransitionException extends \DomainException
{
    private string $fromState;
    private string $toState;

    public function __construct(string $fromState, string $toState, int $code = 0, ?\Throwable $previous = null)
    {
        $this->fromState = $fromState;
        $this->toState = $toState;
        parent::__construct("Invalid state transition from {$fromState} to {$toState}", $code, $previous);
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
            'details' => [
                'from_state' => $this->fromState,
                'to_state' => $this->toState,
            ],
        ];
    }
}
