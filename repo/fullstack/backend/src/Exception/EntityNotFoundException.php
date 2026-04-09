<?php

declare(strict_types=1);

namespace App\Exception;

class EntityNotFoundException extends \RuntimeException
{
    private string $entityType;
    private string $entityId;

    public function __construct(string $entityType, string $id, int $code = 0, ?\Throwable $previous = null)
    {
        $this->entityType = $entityType;
        $this->entityId = $id;
        // Internal message retains full ID for logging; toArray() masks it.
        parent::__construct("{$entityType} not found: {$id}", $code, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return 404;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function toArray(): array
    {
        return [
            'code' => $this->getHttpStatusCode(),
            'message' => "{$this->entityType} not found",
            'details' => null,
        ];
    }
}
