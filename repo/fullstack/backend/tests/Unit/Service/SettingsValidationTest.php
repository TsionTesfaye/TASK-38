<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Organization;
use App\Entity\Settings;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SettingsValidationTest extends TestCase
{
    private SettingsService $service;
    private Settings&MockObject $settings;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($this->settings);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $this->service = new SettingsService(
            $settingsRepo,
            $this->createMock(EntityManagerInterface::class),
            $orgScope,
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
        );
    }

    private function makeAdmin(): User&MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('admin-1');
        $user->method('getRole')->willReturn(UserRole::ADMINISTRATOR);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn('admin');
        return $user;
    }

    // ─── recurring_bill_day ──────────────────────────────────────────

    public function testRejectsBillDayZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recurring_bill_day');
        $this->service->updateSettings($this->makeAdmin(), ['recurring_bill_day' => 0]);
    }

    public function testRejectsBillDay29(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateSettings($this->makeAdmin(), ['recurring_bill_day' => 29]);
    }

    public function testAcceptsBillDay1(): void
    {
        $this->settings->expects($this->once())->method('setRecurringBillDay')->with(1);
        $this->service->updateSettings($this->makeAdmin(), ['recurring_bill_day' => 1]);
    }

    public function testAcceptsBillDay28(): void
    {
        $this->settings->expects($this->once())->method('setRecurringBillDay')->with(28);
        $this->service->updateSettings($this->makeAdmin(), ['recurring_bill_day' => 28]);
    }

    // ─── recurring_bill_hour ─────────────────────────────────────────

    public function testRejectsBillHourNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateSettings($this->makeAdmin(), ['recurring_bill_hour' => -1]);
    }

    public function testRejectsBillHour24(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateSettings($this->makeAdmin(), ['recurring_bill_hour' => 24]);
    }

    public function testAcceptsBillHour0(): void
    {
        $this->settings->expects($this->once())->method('setRecurringBillHour')->with(0);
        $this->service->updateSettings($this->makeAdmin(), ['recurring_bill_hour' => 0]);
    }

    public function testAcceptsBillHour23(): void
    {
        $this->settings->expects($this->once())->method('setRecurringBillHour')->with(23);
        $this->service->updateSettings($this->makeAdmin(), ['recurring_bill_hour' => 23]);
    }

    // ─── booking_attempts_per_item_per_minute ────────────────────────

    public function testRejectsThrottleZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateSettings($this->makeAdmin(), ['booking_attempts_per_item_per_minute' => 0]);
    }

    public function testRejectsThrottleTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateSettings($this->makeAdmin(), ['booking_attempts_per_item_per_minute' => 1001]);
    }

    // ─── cancellation_fee_pct ────────────────────────────────────────

    public function testRejectsNegativeCancellationFee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateSettings($this->makeAdmin(), ['cancellation_fee_pct' => '-5']);
    }

    public function testRejectsCancellationFeeOver100(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateSettings($this->makeAdmin(), ['cancellation_fee_pct' => '101']);
    }

    // ─── hold_duration_minutes ───────────────────────────────────────

    public function testRejectsHoldDurationZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateSettings($this->makeAdmin(), ['hold_duration_minutes' => 0]);
    }

    public function testRejectsHoldDurationOver60(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateSettings($this->makeAdmin(), ['hold_duration_minutes' => 61]);
    }

    // ─── timezone ────────────────────────────────────────────────────

    public function testRejectsInvalidTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone');
        $this->service->updateSettings($this->makeAdmin(), ['timezone' => 'Not/A/Timezone']);
    }

    public function testAcceptsValidTimezone(): void
    {
        $this->settings->expects($this->once())->method('setTimezone')->with('America/New_York');
        $this->service->updateSettings($this->makeAdmin(), ['timezone' => 'America/New_York']);
    }
}
