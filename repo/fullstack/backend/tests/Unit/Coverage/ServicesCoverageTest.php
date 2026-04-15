<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Organization;
use App\Entity\Settings;
use App\Repository\SettingsRepository;
use App\Service\HealthService;
use App\Service\OrgTimeService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for small/utility services.
 */
class ServicesCoverageTest extends TestCase
{
    public function testHealthServiceOk(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($this->createMock(\Doctrine\DBAL\Result::class));

        $s = new HealthService($conn);
        $r = $s->checkHealth();

        $this->assertSame('ok', $r['status']);
        $this->assertSame('ok', $r['checks']['database']);
    }

    public function testHealthServiceDegradedWhenDbFails(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willThrowException(new \RuntimeException('conn lost'));

        $s = new HealthService($conn);
        $r = $s->checkHealth();

        $this->assertSame('degraded', $r['status']);
        $this->assertSame('fail', $r['checks']['database']);
    }

    public function testOrgTimeServiceNowUtc(): void
    {
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

        $s = new OrgTimeService($settingsRepo);
        $now = $s->now('org-x');

        $this->assertSame('UTC', $now->getTimezone()->getName());
        $this->assertLessThan(2, abs($now->getTimestamp() - time()));
    }

    public function testOrgTimeServiceRespectsOrgTimezone(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('getTimezone')->willReturn('America/New_York');

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $s = new OrgTimeService($settingsRepo);
        $tz = $s->getTimezone('org-x');
        $this->assertSame('America/New_York', $tz->getName());

        $now = $s->now('org-x');
        $this->assertSame('America/New_York', $now->getTimezone()->getName());
    }

    public function testOrgTimeServiceCurrentPeriodAndLocalTime(): void
    {
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

        $s = new OrgTimeService($settingsRepo);
        $period = $s->getCurrentPeriod('org-x');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $period);

        $localTime = $s->getCurrentLocalTime('org-x');
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $localTime);
    }

    public function testOrgTimeServiceHoursUntil(): void
    {
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

        $s = new OrgTimeService($settingsRepo);
        $target = new \DateTimeImmutable('+10 hours');
        $hours = $s->hoursUntil($target, 'org-x');
        $this->assertGreaterThan(9.9, $hours);
        $this->assertLessThan(10.1, $hours);

        $pastTarget = new \DateTimeImmutable('-5 hours');
        $pastHours = $s->hoursUntil($pastTarget, 'org-x');
        $this->assertLessThan(0, $pastHours);
    }
}
