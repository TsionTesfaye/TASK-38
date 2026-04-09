<?php

declare(strict_types=1);

namespace App\Exception;

class InvalidEnumException extends \InvalidArgumentException
{
    /** @param string[] $allowedValues */
    public function __construct(
        private readonly string $field,
        private readonly array $allowedValues,
        string $given,
    ) {
        parent::__construct(sprintf(
            'Invalid value "%s" for field "%s". Allowed: %s',
            $given,
            $field,
            implode(', ', $allowedValues),
        ));
    }

    public function getHttpStatusCode(): int
    {
        return 422;
    }

    public function toArray(): array
    {
        return [
            'code' => 422,
            'error' => 'invalid_enum',
            'field' => $this->field,
            'message' => $this->getMessage(),
            'allowed_values' => $this->allowedValues,
        ];
    }
}
