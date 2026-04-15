<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\IdempotencyKey;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Metrics\MetricsCollector;
use App\Metrics\RequestMetricsListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class MetricsAndSmallClassesTest extends TestCase
{
    public function testMetricsCollectorRecordLatency(): void
    {
        $c = new MetricsCollector();
        $c->recordLatency('GET /x', 12.5);
        $c->recordLatency('GET /x', 100.0);
        $c->recordLatency('GET /y', 50.0);

        $this->assertGreaterThan(0, $c->getLatencyP50());
        $this->assertGreaterThanOrEqual($c->getLatencyP50(), $c->getLatencyP95());

        $summary = $c->getSummary();
        $this->assertArrayHasKey('latency_p50_ms', $summary);
        $this->assertArrayHasKey('latency_p95_ms', $summary);
        $this->assertArrayHasKey('error_rate', $summary);
    }

    public function testMetricsCollectorLatencyCapAt1000(): void
    {
        $c = new MetricsCollector();
        // Fill beyond 1000 → should trigger the slice-to-500 path
        for ($i = 0; $i < 1200; $i++) {
            $c->recordLatency('GET /cap', (float) $i);
        }
        $summary = $c->getSummary();
        $this->assertGreaterThan(0, $summary['latency_p50_ms']);
    }

    public function testMetricsCollectorEmptyLatencies(): void
    {
        $c = new MetricsCollector();
        $this->assertSame(0.0, $c->getLatencyP50());
        $this->assertSame(0.0, $c->getLatencyP95());
        $this->assertSame(0.0, $c->getErrorRate());
    }

    public function testMetricsCollectorErrorCounts(): void
    {
        $c = new MetricsCollector();
        $c->recordError('GET /a', 500);
        $c->recordError('GET /a', 500);
        $c->recordError('GET /b', 404);

        $summary = $c->getSummary();
        $this->assertSame(2, $summary['error_counts']['GET /a:500']);
        $this->assertSame(1, $summary['error_counts']['GET /b:404']);
    }

    public function testMetricsCollectorQueueDepthsAndJobs(): void
    {
        $c = new MetricsCollector();
        $c->setNotificationQueueDepth(10);
        $c->setTransferQueueDepth(5);
        $c->incrementFailedJobs();
        $c->incrementFailedJobs();

        $s = $c->getSummary();
        $this->assertSame(10, $s['notification_queue_depth']);
        $this->assertSame(5, $s['transfer_queue_depth']);
        $this->assertSame(2, $s['failed_job_count']);
    }

    public function testMetricsCollectorSchedulerCounters(): void
    {
        $c = new MetricsCollector();
        $c->incrementSchedulerSuccess('expire_holds');
        $c->incrementSchedulerSuccess('expire_holds');
        $c->incrementSchedulerFailure('recon');

        $this->assertSame(2, $c->getSchedulerSuccess()['expire_holds']);
        $this->assertSame(1, $c->getSchedulerFailure()['recon']);
        // Failure increments failed job count
        $this->assertSame(1, $c->getSummary()['failed_job_count']);
    }

    public function testMetricsCollectorErrorRate(): void
    {
        $c = new MetricsCollector();
        $c->recordLatency('GET /x', 10);
        $c->recordLatency('GET /x', 20);
        $c->recordLatency('GET /x', 30);
        $c->recordLatency('GET /x', 40);
        $c->recordError('GET /x', 500);

        $this->assertSame(0.25, $c->getErrorRate());
    }

    public function testRequestMetricsListenerRecordsLatencyAndErrors(): void
    {
        $c = new MetricsCollector();
        $listener = new RequestMetricsListener($c);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $request = Request::create('/api/v1/test', 'GET');
        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        usleep(1000);
        $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 200)));

        $summary = $c->getSummary();
        $this->assertGreaterThanOrEqual(0, $summary['latency_p50_ms']);

        // Error case
        $request2 = Request::create('/api/v1/err', 'POST');
        $listener->onRequest(new RequestEvent($kernel, $request2, HttpKernelInterface::MAIN_REQUEST));
        $listener->onResponse(new ResponseEvent($kernel, $request2, HttpKernelInterface::MAIN_REQUEST, new Response('', 500)));

        $summary = $c->getSummary();
        $this->assertNotEmpty($summary['error_counts']);
    }

    public function testIdempotencyKeyEntity(): void
    {
        $org = new Organization('o-1', 'O', 'n');
        $user = new User('u-1', $org, 'n', 'h', 'd', UserRole::TENANT);
        $expires = new \DateTimeImmutable('+1 hour');
        $pastExpires = new \DateTimeImmutable('-1 hour');

        $k = new IdempotencyKey('k-1', $user, 'req-1', ['x' => 1], $expires);
        $this->assertSame('k-1', $k->getId());
        $this->assertSame('u-1', $k->getUserId());
        $this->assertSame('req-1', $k->getRequestKey());
        $this->assertSame(['x' => 1], $k->getResponsePayloadJson());
        $this->assertFalse($k->isExpired());

        $expired = new IdempotencyKey('k-2', $user, 'req-2', [], $pastExpires);
        $this->assertTrue($expired->isExpired());
    }
}
