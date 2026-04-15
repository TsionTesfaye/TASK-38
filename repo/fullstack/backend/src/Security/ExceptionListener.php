<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
class ExceptionListener
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($this->isAppException($exception)) {
            $statusCode = method_exists($exception, 'getHttpStatusCode')
                ? $exception->getHttpStatusCode()
                : 500;

            $body = method_exists($exception, 'toArray')
                ? $exception->toArray()
                : [
                    'code' => $statusCode,
                    'message' => $exception->getMessage(),
                    'details' => null,
                ];

            // Redact any UUIDs that may have leaked into the message field.
            $body['message'] = $this->redactSensitive($body['message']);

            $event->setResponse(new JsonResponse($body, $statusCode));
            return;
        }

        // DomainException → 409 Conflict (state machine violations, business rule violations)
        if ($exception instanceof \DomainException) {
            $event->setResponse(new JsonResponse([
                'code' => 409,
                'message' => $this->redactSensitive($exception->getMessage()),
                'details' => null,
            ], 409));
            return;
        }

        // InvalidArgumentException → 422 Unprocessable Entity (validation failures)
        if ($exception instanceof \InvalidArgumentException) {
            $event->setResponse(new JsonResponse([
                'code' => 422,
                'message' => $this->redactSensitive($exception->getMessage()),
                'details' => null,
            ], 422));
            return;
        }

        // Symfony HTTP exceptions (NotFoundHttpException → 404, etc.)
        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage() ?: match ($statusCode) {
                404 => 'Not found',
                405 => 'Method not allowed',
                default => 'HTTP error',
            };
            $event->setResponse(new JsonResponse([
                'code'    => $statusCode,
                'message' => $message,
                'details' => null,
            ], $statusCode));
            return;
        }

        // Log redacted details internally — no raw IDs, no full stack traces.
        $this->logger->error('Unhandled exception', [
            'class'   => get_class($exception),
            'message' => $this->redactSensitive($exception->getMessage()),
            'file'    => basename($exception->getFile()) . ':' . $exception->getLine(),
        ]);

        $event->setResponse(new JsonResponse([
            'code'    => 500,
            'message' => 'Internal server error',
            'details' => null,
        ], 500));
    }

    private function isAppException(\Throwable $exception): bool
    {
        return str_starts_with(get_class($exception), 'App\\Exception\\');
    }

    /**
     * Mask UUIDs and long hex strings in messages to prevent sensitive ID leakage.
     */
    public function redactSensitive(string $message): string
    {
        // Mask UUIDs: show only last 4 chars
        return preg_replace_callback(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            fn(array $m) => '****' . substr($m[0], -4),
            $message,
        ) ?? $message;
    }
}
