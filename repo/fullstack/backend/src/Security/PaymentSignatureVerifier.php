<?php

declare(strict_types=1);

namespace App\Security;

class PaymentSignatureVerifier
{
    private string $sharedSecret;

    public function __construct()
    {
        $this->sharedSecret = $_ENV['PAYMENT_SHARED_SECRET'] ?? throw new \RuntimeException('PAYMENT_SHARED_SECRET environment variable is required');
    }

    public function generateSignature(array $payload): string
    {
        ksort($payload);
        $data = json_encode($payload);

        return hash_hmac('sha256', $data, $this->sharedSecret);
    }

    public function verifySignature(string $signature, array $payload): bool
    {
        $expected = $this->generateSignature($payload);

        return hash_equals($expected, $signature);
    }
}
