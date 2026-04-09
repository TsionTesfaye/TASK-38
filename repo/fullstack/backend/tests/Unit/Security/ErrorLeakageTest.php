<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ExceptionListener;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Ensures the ExceptionListener never leaks internal error details to the client.
 */
class ErrorLeakageTest extends TestCase
{
    private ExceptionListener $listener;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new ExceptionListener($this->logger);
    }

    private function makeEvent(\Throwable $exception): ExceptionEvent
    {
        /** @var KernelInterface&MockObject $kernel */
        $kernel = $this->createMock(KernelInterface::class);
        $request = Request::create('/api/v1/test');

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }

    // ─── Non-App exceptions ────────────────────────────────────────────────

    public function testDatabaseExceptionReturnsGenericMessage(): void
    {
        $event = $this->makeEvent(new \RuntimeException('Connection refused to db-host:5432'));

        $this->listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $body = json_decode($response->getContent(), true);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Internal server error', $body['message']);
        $this->assertStringNotContainsString('db-host', $response->getContent());
        $this->assertStringNotContainsString('Connection refused', $response->getContent());
    }

    public function testUnexpectedExceptionLogsInternally(): void
    {
        $event = $this->makeEvent(new \RuntimeException('secret internal detail'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Unhandled exception',
                $this->callback(fn(array $ctx) =>
                    str_contains($ctx['message'], 'secret internal detail') &&
                    $ctx['class'] === \RuntimeException::class
                )
            );

        $this->listener->onKernelException($event);
    }

    public function testNonAppExceptionDoesNotLeakStackTrace(): void
    {
        $event = $this->makeEvent(new \PDOException('SQLSTATE[23000]: Integrity constraint violation'));

        $this->listener->onKernelException($event);

        $body = $event->getResponse()->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('stack', strtolower($body));
        $this->assertStringContainsString('Internal server error', $body);
    }

    // ─── App exceptions DO propagate their messages ────────────────────────

    public function testAppAccessDeniedExceptionPropagatesToClient(): void
    {
        $event = $this->makeEvent(new \App\Exception\AccessDeniedException());

        $this->logger->expects($this->never())->method('error');

        $this->listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAppEntityNotFoundExceptionPropagatesToClient(): void
    {
        $event = $this->makeEvent(new \App\Exception\EntityNotFoundException('Bill', 'bill-1'));

        $this->listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Bill', $body['message']);
    }

    // ─── Non-API exceptions are NOT handled ───────────────────────────────

    public function testNonApiExceptionSetsResponse(): void
    {
        // ExceptionListener handles ALL paths once triggered — it's up to Symfony
        // routing to only invoke it for API paths.
        $event = $this->makeEvent(new \RuntimeException('generic'));
        $this->listener->onKernelException($event);

        // Response IS set (generic 500).
        $this->assertNotNull($event->getResponse());
    }
}
