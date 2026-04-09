<?php

declare(strict_types=1);

namespace App\Metrics;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records request latency and error metrics into MetricsCollector.
 * Priority: runs early on request, late on response/exception.
 */
class RequestMetricsListener
{
    private const REQUEST_START_ATTR = '_metrics_start';

    public function __construct(private readonly MetricsCollector $collector) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 1000)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $event->getRequest()->attributes->set(self::REQUEST_START_ATTR, microtime(true));
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1000)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        $start = $request->attributes->get(self::REQUEST_START_ATTR);
        if ($start === null) return;

        $endpoint = $request->getMethod() . ' ' . $request->getPathInfo();
        $durationMs = (microtime(true) - $start) * 1000;
        $this->collector->recordLatency($endpoint, $durationMs);

        $status = $event->getResponse()->getStatusCode();
        if ($status >= 400) {
            $this->collector->recordError($endpoint, $status);
        }
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: -1000)]
    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        $endpoint = $request->getMethod() . ' ' . $request->getPathInfo();
        $this->collector->recordError($endpoint, 500);
    }
}
