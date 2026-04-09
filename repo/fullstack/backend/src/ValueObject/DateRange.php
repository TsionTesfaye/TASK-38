<?php

declare(strict_types=1);

namespace App\ValueObject;

final class DateRange implements \JsonSerializable
{
    private readonly \DateTimeImmutable $start;
    private readonly \DateTimeImmutable $end;

    public function __construct(\DateTimeImmutable $start, \DateTimeImmutable $end)
    {
        if ($start >= $end) {
            throw new \InvalidArgumentException('Start date must be before end date.');
        }

        $this->start = $start;
        $this->end = $end;
    }

    public function getStart(): \DateTimeImmutable
    {
        return $this->start;
    }

    public function getEnd(): \DateTimeImmutable
    {
        return $this->end;
    }

    public function overlaps(DateRange $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        return $date >= $this->start && $date <= $this->end;
    }

    public function durationInDays(): int
    {
        return (int) $this->start->diff($this->end)->days;
    }

    public function durationInHours(): float
    {
        $diffSeconds = $this->end->getTimestamp() - $this->start->getTimestamp();

        return round($diffSeconds / 3600, 2);
    }

    public function jsonSerialize(): array
    {
        return [
            'start' => $this->start->format(\DateTimeInterface::ATOM),
            'end' => $this->end->format(\DateTimeInterface::ATOM),
        ];
    }
}
