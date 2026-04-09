<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;

/**
 * Centralized time utility for organization-aware time operations.
 * ALL time-dependent business logic MUST use this service.
 * Never use `new \DateTimeImmutable()` without timezone in services.
 */
class OrgTimeService
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository,
    ) {}

    /**
     * Get current time in the organization's configured timezone.
     */
    public function now(string $orgId): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->getTimezone($orgId));
    }

    /**
     * Get the organization's configured timezone.
     */
    public function getTimezone(string $orgId): \DateTimeZone
    {
        $settings = $this->settingsRepository->findByOrganizationId($orgId);
        $tz = $settings !== null ? $settings->getTimezone() : 'UTC';
        return new \DateTimeZone($tz);
    }

    /**
     * Get current period key (Y-m) in org timezone for billing dedup.
     */
    public function getCurrentPeriod(string $orgId): string
    {
        return $this->now($orgId)->format('Y-m');
    }

    /**
     * Get current local time string (H:i) for DND comparison.
     */
    public function getCurrentLocalTime(string $orgId): string
    {
        return $this->now($orgId)->format('H:i');
    }

    /**
     * Check if a given time is before a deadline (org-aware).
     * Used for cancellation 24h check.
     */
    public function hoursUntil(\DateTimeImmutable $target, string $orgId): float
    {
        $now = $this->now($orgId);
        return ($target->getTimestamp() - $now->getTimestamp()) / 3600;
    }
}
