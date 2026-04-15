<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Exception\CurrencyMismatchException;
use App\ValueObject\DateRange;
use App\ValueObject\Money;
use App\ValueObject\PaginationResult;
use PHPUnit\Framework\TestCase;

class ValueObjectsAndDtosCoverageTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Money
    // ═══════════════════════════════════════════════════════════════

    public function testMoneyConstructorRejectsBadCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money('1.00', 'TOO-LONG');
    }

    public function testMoneyBasicProperties(): void
    {
        $m = new Money('1.5', 'usd');
        $this->assertSame('1.50', $m->getAmount());
        $this->assertSame('USD', $m->getCurrency());
        $this->assertFalse($m->isZero());
    }

    public function testMoneyZeroFactory(): void
    {
        $z = Money::zero('EUR');
        $this->assertTrue($z->isZero());
        $this->assertSame('EUR', $z->getCurrency());
    }

    public function testMoneyArithmeticAndComparisons(): void
    {
        $a = new Money('10.00', 'USD');
        $b = new Money('3.50', 'USD');

        $sum = $a->add($b);
        $this->assertSame('13.50', $sum->getAmount());

        $diff = $a->subtract($b);
        $this->assertSame('6.50', $diff->getAmount());

        $this->assertTrue($a->isGreaterThan($b));
        $this->assertFalse($b->isGreaterThan($a));
        $this->assertTrue($b->isLessThanOrEqual($a));
        $this->assertFalse($a->isLessThanOrEqual($b));

        $this->assertTrue((new Money('10.00', 'USD'))->equals($a));
        $this->assertFalse($a->equals(new Money('10.00', 'EUR')));
    }

    public function testMoneyCurrencyMismatch(): void
    {
        $this->expectException(CurrencyMismatchException::class);
        (new Money('1.00', 'USD'))->add(new Money('1.00', 'EUR'));
    }

    public function testMoneySubtractCurrencyMismatch(): void
    {
        $this->expectException(CurrencyMismatchException::class);
        (new Money('1.00', 'USD'))->subtract(new Money('1.00', 'EUR'));
    }

    public function testMoneyJsonSerializable(): void
    {
        $m = new Money('5.00', 'USD');
        $json = $m->jsonSerialize();
        $this->assertSame(['amount' => '5.00', 'currency' => 'USD'], $json);
    }

    // ═══════════════════════════════════════════════════════════════
    // PaginationResult
    // ═══════════════════════════════════════════════════════════════

    public function testPaginationResultBasic(): void
    {
        $p = new PaginationResult(['a', 'b'], 1, 10, 25);
        $this->assertSame(['a', 'b'], $p->getItems());
        $this->assertSame(1, $p->getPage());
        $this->assertSame(10, $p->getPerPage());
        $this->assertSame(25, $p->getTotal());
        $this->assertTrue($p->hasNext());
    }

    public function testPaginationResultNoNext(): void
    {
        $p = new PaginationResult([], 3, 10, 25);
        $this->assertFalse($p->hasNext());
    }

    public function testPaginationResultToArrayAndJson(): void
    {
        $p = new PaginationResult(['x'], 1, 5, 3);
        $this->assertSame([
            'items' => ['x'], 'page' => 1, 'per_page' => 5, 'total' => 3, 'has_next' => false,
        ], $p->toArray());
        $this->assertSame($p->toArray(), $p->jsonSerialize());
    }

    public function testPaginationResultInvalidPage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaginationResult([], 0, 10, 0);
    }

    public function testPaginationResultInvalidPerPage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaginationResult([], 1, 0, 0);
    }

    public function testPaginationResultInvalidTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaginationResult([], 1, 10, -1);
    }

    // ═══════════════════════════════════════════════════════════════
    // DateRange
    // ═══════════════════════════════════════════════════════════════

    public function testDateRangeBasic(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $end = new \DateTimeImmutable('2026-01-03T00:00:00Z');
        $r = new DateRange($start, $end);
        $this->assertSame($start, $r->getStart());
        $this->assertSame($end, $r->getEnd());
        $this->assertSame(2, $r->durationInDays());
        $this->assertSame(48.0, $r->durationInHours());
    }

    public function testDateRangeOverlaps(): void
    {
        $r1 = new DateRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-10'));
        $r2 = new DateRange(new \DateTimeImmutable('2026-01-05'), new \DateTimeImmutable('2026-01-15'));
        $r3 = new DateRange(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-10'));
        $this->assertTrue($r1->overlaps($r2));
        $this->assertFalse($r1->overlaps($r3));
    }

    public function testDateRangeContains(): void
    {
        $r = new DateRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-10'));
        $this->assertTrue($r->contains(new \DateTimeImmutable('2026-01-05')));
        $this->assertFalse($r->contains(new \DateTimeImmutable('2025-12-31')));
    }

    public function testDateRangeJsonSerialize(): void
    {
        $r = new DateRange(
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2026-01-02T00:00:00+00:00'),
        );
        $json = $r->jsonSerialize();
        $this->assertArrayHasKey('start', $json);
        $this->assertArrayHasKey('end', $json);
    }

    public function testDateRangeInvalidOrder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DateRange(
            new \DateTimeImmutable('2026-01-10'),
            new \DateTimeImmutable('2026-01-01'),
        );
    }
}
