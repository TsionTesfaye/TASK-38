<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Exception\CurrencyMismatchException;

final class Money implements \JsonSerializable
{
    private readonly string $amount;
    private readonly string $currency;

    public function __construct(string $amount, string $currency)
    {
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('Currency must be a 3-character ISO 4217 code.');
        }

        $this->amount = bcadd($amount, '0', 2);
        $this->currency = strtoupper($currency);
    }

    public static function zero(string $currency): self
    {
        return new self('0', $currency);
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcadd($this->amount, $other->amount, 2), $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcsub($this->amount, $other->amount, 2), $this->currency);
    }

    public function isGreaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, 2) === 1;
    }

    public function isLessThanOrEqual(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, 2) <= 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', 2) === 0;
    }

    public function equals(Money $other): bool
    {
        return $this->currency === $other->currency
            && bccomp($this->amount, $other->amount, 2) === 0;
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatchException(sprintf(
                'Cannot operate on different currencies: %s and %s.',
                $this->currency,
                $other->currency,
            ));
        }
    }
}
