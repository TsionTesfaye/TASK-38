<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\AccountFrozenException;
use App\Exception\AuthenticationException;
use App\Exception\BillVoidException;
use App\Exception\BookingDurationExceededException;
use App\Exception\BootstrapAlreadyCompletedException;
use App\Exception\CurrencyMismatchException;
use App\Exception\DuplicateRequestException;
use App\Exception\EntityNotFoundException;
use App\Exception\HoldExpiredException;
use App\Exception\InsufficientCapacityException;
use App\Exception\InvalidEnumException;
use App\Exception\InvalidStateTransitionException;
use App\Exception\OrganizationScopeMismatchException;
use App\Exception\PaymentValidationException;
use App\Exception\RefundExceededException;
use App\Exception\ThrottleLimitException;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive coverage for every custom exception:
 * constructor, getHttpStatusCode(), toArray().
 */
class ExceptionsCoverageTest extends TestCase
{
    private function assertErrorShape(array $arr, int $expectedCode): void
    {
        $this->assertArrayHasKey('code', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertSame($expectedCode, $arr['code']);
        $this->assertIsString($arr['message']);
    }

    public function testAccessDeniedException(): void
    {
        $e = new AccessDeniedException();
        $this->assertSame(403, $e->getHttpStatusCode());
        $this->assertErrorShape($e->toArray(), 403);

        $custom = new AccessDeniedException('Custom message');
        $this->assertSame('Custom message', $custom->getMessage());
    }

    public function testAccountFrozenException(): void
    {
        $e = new AccountFrozenException();
        $this->assertSame(403, $e->getHttpStatusCode());
        $this->assertIsArray($e->toArray());
    }

    public function testAuthenticationException(): void
    {
        $e = new AuthenticationException();
        $this->assertSame(401, $e->getHttpStatusCode());
        $this->assertErrorShape($e->toArray(), 401);
    }

    public function testBillVoidException(): void
    {
        $e = new BillVoidException('already voided');
        $this->assertSame(409, $e->getHttpStatusCode());
        $this->assertErrorShape($e->toArray(), 409);
        $this->assertStringContainsString('already voided', $e->getMessage());
    }

    public function testBookingDurationExceededException(): void
    {
        $e = new BookingDurationExceededException();
        $this->assertGreaterThanOrEqual(400, $e->getHttpStatusCode());
        $this->assertErrorShape($e->toArray(), $e->getHttpStatusCode());
    }

    public function testBootstrapAlreadyCompletedException(): void
    {
        $e = new BootstrapAlreadyCompletedException();
        $this->assertSame(409, $e->getHttpStatusCode());
        $this->assertErrorShape($e->toArray(), 409);
    }

    public function testCurrencyMismatchException(): void
    {
        $e = new CurrencyMismatchException();
        $this->assertGreaterThanOrEqual(400, $e->getHttpStatusCode());
        $this->assertErrorShape($e->toArray(), $e->getHttpStatusCode());
    }

    public function testDuplicateRequestException(): void
    {
        $prev = ['data' => ['id' => '123']];
        $e = new DuplicateRequestException($prev);
        $this->assertSame(409, $e->getHttpStatusCode());
        $arr = $e->toArray();
        $this->assertSame(409, $arr['code']);
    }

    public function testEntityNotFoundException(): void
    {
        $uuid = 'abc12345-e29b-41d4-a716-446655440000';
        $e = new EntityNotFoundException('Booking', $uuid);
        $this->assertSame(404, $e->getHttpStatusCode());
        $this->assertSame('Booking', $e->getEntityType());
        $arr = $e->toArray();
        $this->assertSame('Booking not found', $arr['message']);
        $this->assertStringContainsString($uuid, $e->getMessage());
        // Public array MUST NOT contain the full UUID
        $this->assertStringNotContainsString($uuid, json_encode($arr));
    }

    public function testHoldExpiredException(): void
    {
        $e = new HoldExpiredException();
        $this->assertGreaterThanOrEqual(400, $e->getHttpStatusCode());
        $this->assertErrorShape($e->toArray(), $e->getHttpStatusCode());
    }

    public function testInsufficientCapacityException(): void
    {
        $e = new InsufficientCapacityException();
        $this->assertGreaterThanOrEqual(400, $e->getHttpStatusCode());
        $this->assertErrorShape($e->toArray(), $e->getHttpStatusCode());
    }

    public function testInvalidEnumException(): void
    {
        $allowed = array_map(fn($c) => $c->value, UserRole::cases());
        $e = new InvalidEnumException('role', $allowed, 'bad_value');
        $this->assertSame(422, $e->getHttpStatusCode());
        $arr = $e->toArray();
        $this->assertSame(422, $arr['code']);
        $this->assertSame('role', $arr['field']);
        $this->assertIsArray($arr['allowed_values']);
        $this->assertContains('administrator', $arr['allowed_values']);
        $this->assertStringContainsString('bad_value', $e->getMessage());
    }

    public function testInvalidStateTransitionException(): void
    {
        $e = new InvalidStateTransitionException('confirmed', 'no_show');
        $this->assertSame(409, $e->getHttpStatusCode());
        $arr = $e->toArray();
        $this->assertSame(409, $arr['code']);
    }

    public function testOrganizationScopeMismatchException(): void
    {
        $e = new OrganizationScopeMismatchException();
        $this->assertInstanceOf(\Exception::class, $e);
        if (method_exists($e, 'getHttpStatusCode')) {
            $this->assertGreaterThanOrEqual(400, $e->getHttpStatusCode());
        }
    }

    public function testPaymentValidationException(): void
    {
        $e = new PaymentValidationException('amount mismatch');
        $this->assertSame(422, $e->getHttpStatusCode());
        $arr = $e->toArray();
        $this->assertSame(422, $arr['code']);
        $this->assertStringContainsString('amount mismatch', $e->getMessage());
    }

    public function testRefundExceededException(): void
    {
        $e = new RefundExceededException();
        $this->assertGreaterThanOrEqual(400, $e->getHttpStatusCode());
        $this->assertErrorShape($e->toArray(), $e->getHttpStatusCode());
    }

    public function testThrottleLimitException(): void
    {
        $e = new ThrottleLimitException();
        $this->assertSame(429, $e->getHttpStatusCode());
        $this->assertErrorShape($e->toArray(), 429);
    }
}
