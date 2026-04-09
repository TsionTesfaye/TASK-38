<?php

declare(strict_types=1);

namespace App\DTO\Response;

readonly class ErrorResponse
{
    public function __construct(
        public int $code,
        public string $message,
        public ?array $details = null,
    ) {}

    public function toArray(): array
    {
        $result = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->details !== null) {
            $result['details'] = $this->details;
        }

        return $result;
    }
}
