<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\Settings;
use App\Entity\User;
use App\Exception\EntityNotFoundException;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use Doctrine\ORM\EntityManagerInterface;

class SettingsService
{
    public function __construct(
        private readonly SettingsRepository $settingsRepo,
        private readonly EntityManagerInterface $em,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
        private readonly AuditService $auditService,
    ) {}

    public function getSettings(User $user): Settings
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_SETTINGS);
        $orgId = $this->orgScope->getOrganizationId($user);
        $settings = $this->settingsRepo->findByOrganizationId($orgId);
        if (!$settings) { throw new EntityNotFoundException('Settings', $orgId); }
        return $settings;
    }

    public function updateSettings(User $admin, array $data): Settings
    {
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_SETTINGS);
        $settings = $this->getSettings($admin);

        // Validate before applying
        $this->validateSettingsPayload($data);

        if (isset($data['timezone'])) {
            try { new \DateTimeZone($data['timezone']); } catch (\Exception) {
                throw new \InvalidArgumentException('Invalid timezone: ' . $data['timezone']);
            }
            $settings->setTimezone($data['timezone']);
        }
        if (isset($data['allow_partial_payments'])) { $settings->setAllowPartialPayments((bool) $data['allow_partial_payments']); }
        if (isset($data['cancellation_fee_pct'])) { $settings->setCancellationFeePct((string) $data['cancellation_fee_pct']); }
        if (isset($data['no_show_fee_pct'])) { $settings->setNoShowFeePct((string) $data['no_show_fee_pct']); }
        if (isset($data['no_show_first_day_rent_enabled'])) { $settings->setNoShowFirstDayRentEnabled((bool) $data['no_show_first_day_rent_enabled']); }
        if (isset($data['hold_duration_minutes'])) { $settings->setHoldDurationMinutes((int) $data['hold_duration_minutes']); }
        if (isset($data['no_show_grace_period_minutes'])) { $settings->setNoShowGracePeriodMinutes((int) $data['no_show_grace_period_minutes']); }
        if (isset($data['max_devices_per_user'])) { $settings->setMaxDevicesPerUser((int) $data['max_devices_per_user']); }
        if (isset($data['terminals_enabled'])) { $settings->setTerminalsEnabled((bool) $data['terminals_enabled']); }
        if (isset($data['max_booking_duration_days'])) { $settings->setMaxBookingDurationDays((int) $data['max_booking_duration_days']); }
        if (isset($data['recurring_bill_day'])) { $settings->setRecurringBillDay((int) $data['recurring_bill_day']); }
        if (isset($data['recurring_bill_hour'])) { $settings->setRecurringBillHour((int) $data['recurring_bill_hour']); }
        if (isset($data['booking_attempts_per_item_per_minute'])) { $settings->setBookingAttemptsPerItemPerMinute((int) $data['booking_attempts_per_item_per_minute']); }
        if (isset($data['notification_templates']) && is_array($data['notification_templates'])) {
            $settings->setNotificationTemplates($data['notification_templates']);
        }

        $this->em->flush();
        $this->auditService->log($admin->getOrganizationId(), $admin, $admin->getUsername(), 'SETTINGS_UPDATED', 'Settings', $settings->getId());
        return $settings;
    }

    private function validateSettingsPayload(array $data): void
    {
        $errors = [];

        if (isset($data['recurring_bill_day'])) {
            $day = (int) $data['recurring_bill_day'];
            if ($day < 1 || $day > 28) {
                $errors[] = 'recurring_bill_day must be between 1 and 28';
            }
        }

        if (isset($data['recurring_bill_hour'])) {
            $hour = (int) $data['recurring_bill_hour'];
            if ($hour < 0 || $hour > 23) {
                $errors[] = 'recurring_bill_hour must be between 0 and 23';
            }
        }

        if (isset($data['booking_attempts_per_item_per_minute'])) {
            $throttle = (int) $data['booking_attempts_per_item_per_minute'];
            if ($throttle < 1 || $throttle > 1000) {
                $errors[] = 'booking_attempts_per_item_per_minute must be between 1 and 1000';
            }
        }

        if (isset($data['hold_duration_minutes'])) {
            $hold = (int) $data['hold_duration_minutes'];
            if ($hold < 1 || $hold > 60) {
                $errors[] = 'hold_duration_minutes must be between 1 and 60';
            }
        }

        if (isset($data['no_show_grace_period_minutes'])) {
            $grace = (int) $data['no_show_grace_period_minutes'];
            if ($grace < 0 || $grace > 1440) {
                $errors[] = 'no_show_grace_period_minutes must be between 0 and 1440';
            }
        }

        if (isset($data['max_devices_per_user'])) {
            $devices = (int) $data['max_devices_per_user'];
            if ($devices < 1 || $devices > 5) {
                $errors[] = 'max_devices_per_user must be between 1 and 5';
            }
        }

        if (isset($data['max_booking_duration_days'])) {
            $days = (int) $data['max_booking_duration_days'];
            if ($days < 1 || $days > 3650) {
                $errors[] = 'max_booking_duration_days must be between 1 and 3650';
            }
        }

        if (isset($data['cancellation_fee_pct'])) {
            $pct = (float) $data['cancellation_fee_pct'];
            if ($pct < 0 || $pct > 100) {
                $errors[] = 'cancellation_fee_pct must be between 0 and 100';
            }
        }

        if (isset($data['no_show_fee_pct'])) {
            $pct = (float) $data['no_show_fee_pct'];
            if ($pct < 0 || $pct > 100) {
                $errors[] = 'no_show_fee_pct must be between 0 and 100';
            }
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }
}
