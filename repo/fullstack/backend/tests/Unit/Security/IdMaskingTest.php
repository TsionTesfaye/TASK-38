<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Exception\EntityNotFoundException;
use App\Exception\InvalidEnumException;
use App\Exception\PaymentValidationException;
use App\Security\ExceptionListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Proves that raw entity IDs never leak to clients:
 *   1. EntityNotFoundException.toArray() contains no raw UUIDs
 *   2. ExceptionListener redacts UUIDs in all client-facing responses
 *   3. Masking policy is consistent (last 4 chars or full removal)
 */
class IdMaskingTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // 1. EntityNotFoundException serialization
    // ═══════════════════════════════════════════════════════════════

    public function testEntityNotFoundToArrayContainsNoRawId(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $e = new EntityNotFoundException('Booking', $uuid);
        $arr = $e->toArray();

        $this->assertSame(404, $arr['code']);
        $this->assertSame('Booking not found', $arr['message']);
        $this->assertNull($arr['details'], 'Details must be null — no raw ID');

        // Double-check: the serialized output must not contain the full UUID anywhere
        $json = json_encode($arr);
        $this->assertStringNotContainsString($uuid, $json);
        $this->assertStringNotContainsString('550e8400', $json);
    }

    public function testEntityNotFoundMessageDoesNotContainId(): void
    {
        $e = new EntityNotFoundException('Payment', 'abc12345-6789-0def-ghij-klmnopqrstuv');
        $arr = $e->toArray();

        $this->assertStringNotContainsString('abc12345', $arr['message']);
        $this->assertStringNotContainsString('klmnopqrstuv', $arr['message']);
        $this->assertSame('Payment not found', $arr['message']);
    }

    public function testEntityNotFoundInternalMessageRetainsIdForLogging(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $e = new EntityNotFoundException('Booking', $uuid);

        // Internal getMessage() retains the full ID for log correlation
        $this->assertStringContainsString($uuid, $e->getMessage());
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. ExceptionListener UUID redaction in client responses
    // ═══════════════════════════════════════════════════════════════

    private function simulateException(\Throwable $exception): array
    {
        $listener = new ExceptionListener(new NullLogger());
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/v1/test', 'GET');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        return json_decode($response->getContent(), true);
    }

    public function testListenerRedactsUuidsInAppExceptionMessages(): void
    {
        // EntityNotFoundException.toArray() already returns clean message,
        // but test the redaction layer independently with a custom exception
        // whose getMessage() contains a UUID.
        $e = new PaymentValidationException('Payment req-550e8400-e29b-41d4-a716-446655440000 failed');
        $body = $this->simulateException($e);

        $this->assertSame(422, $body['code']);
        $this->assertStringNotContainsString('550e8400-e29b-41d4-a716-446655440000', $body['message']);
        $this->assertStringContainsString('****', $body['message']);
    }

    public function testListenerRedactsUuidsInDomainExceptionMessages(): void
    {
        $e = new \DomainException('Hold abc12345-6789-0def-ghij-klmnopqrstuv is not active');
        $body = $this->simulateException($e);

        $this->assertSame(409, $body['code']);
        $this->assertStringNotContainsString('abc12345-6789-0def-ghij-klmnopqrstuv', $body['message']);
    }

    public function testListenerRedactsUuidsInInvalidArgumentMessages(): void
    {
        $e = new \InvalidArgumentException('Invalid ref: 12345678-1234-1234-1234-123456789abc');
        $body = $this->simulateException($e);

        $this->assertSame(422, $body['code']);
        $this->assertStringNotContainsString('12345678-1234-1234-1234-123456789abc', $body['message']);
    }

    public function testListenerReturns500WithoutLeakingForUnhandled(): void
    {
        $e = new \RuntimeException('PDO error in table users id=550e8400-e29b-41d4-a716-446655440000');
        $body = $this->simulateException($e);

        $this->assertSame(500, $body['code']);
        $this->assertSame('Internal server error', $body['message']);
        $this->assertNull($body['details']);
    }

    public function testEntityNotFoundViaListenerIsClean(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $e = new EntityNotFoundException('Bill', $uuid);
        $body = $this->simulateException($e);

        $this->assertSame(404, $body['code']);
        $this->assertSame('Bill not found', $body['message']);
        $this->assertNull($body['details']);

        $json = json_encode($body);
        $this->assertStringNotContainsString($uuid, $json);
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. Masking policy consistency
    // ═══════════════════════════════════════════════════════════════

    public function testRedactSensitiveMethodMasksAllUuidFormats(): void
    {
        $listener = new ExceptionListener(new NullLogger());

        // Standard UUID v4
        $result = $listener->redactSensitive('Entity 550e8400-e29b-41d4-a716-446655440000 not found');
        $this->assertStringNotContainsString('550e8400', $result);
        $this->assertStringContainsString('****0000', $result);

        // Multiple UUIDs in one message
        $result = $listener->redactSensitive(
            'Bill a1111111-b222-c333-d444-e55555555555 references Payment f6666666-a777-b888-c999-d00000000000',
        );
        $this->assertStringNotContainsString('a1111111', $result);
        $this->assertStringNotContainsString('f6666666', $result);
        $this->assertSame(2, substr_count($result, '****'));
    }

    public function testRedactSensitiveLeavesNonUuidStringsAlone(): void
    {
        $listener = new ExceptionListener(new NullLogger());

        $clean = 'Invalid credentials';
        $this->assertSame($clean, $listener->redactSensitive($clean));

        $clean2 = 'Payment amount must be greater than zero';
        $this->assertSame($clean2, $listener->redactSensitive($clean2));
    }

    public function testSafeExceptionsPassThroughUnchanged(): void
    {
        // Exceptions with no UUIDs in their messages should pass through cleanly
        $e = new \App\Exception\AccessDeniedException();
        $body = $this->simulateException($e);

        $this->assertSame(403, $body['code']);
        $this->assertArrayHasKey('message', $body);
    }
}
