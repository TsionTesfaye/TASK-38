<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditActions;
use App\Entity\Booking;
use App\Entity\BookingEvent;
use App\Entity\User;
use App\Enum\BookingEventType;
use App\Enum\BookingHoldStatus;
use App\Enum\BookingStatus;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Repository\BookingEventRepository;
use App\Repository\BookingRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class BookingService
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly BookingEventRepository $bookingEventRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly BillingService $billingService,
        private readonly PricingService $pricingService,
        private readonly BookingHoldService $holdService,
        private readonly LedgerService $ledgerService,
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
        private readonly EntityManagerInterface $em,
        private readonly OrgTimeService $orgTimeService,
    ) {}

    public function checkIn(User $manager, string $bookingId): Booking
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_CHECK_IN);
        $orgId = $this->orgScope->getOrganizationId($manager);
        $booking = $this->findBookingInOrg($bookingId, $orgId);

        if ($booking->getStatus() !== BookingStatus::CONFIRMED) {
            throw new \DomainException('Booking must be in CONFIRMED status to check in');
        }

        $beforeStatus = $booking->getStatus();
        $booking->markCheckedIn();

        $event = new BookingEvent(
            Uuid::v4()->toRfc4122(),
            $booking,
            $manager,
            BookingEventType::ACTIVATED,
            $beforeStatus,
            BookingStatus::ACTIVE,
        );
        $this->em->persist($event);
        $this->em->flush();

        $this->auditService->log(
            $orgId,
            $manager,
            $manager->getUsername(),
            AuditActions::BOOKING_CHECKED_IN,
            'Booking',
            $bookingId,
            ['status' => $beforeStatus->value],
            ['status' => BookingStatus::ACTIVE->value],
        );

        $this->notificationService->createNotification($orgId, $booking->getTenantUserId(), 'booking.checked_in', 'Check-In Confirmed', 'You have been checked in for your booking.');

        return $booking;
    }

    public function complete(User $manager, string $bookingId): Booking
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_BOOKINGS);
        $orgId = $this->orgScope->getOrganizationId($manager);
        $booking = $this->findBookingInOrg($bookingId, $orgId);

        if ($booking->getStatus() !== BookingStatus::ACTIVE) {
            throw new \DomainException('Booking must be in ACTIVE status to complete');
        }

        $beforeStatus = $booking->getStatus();
        $booking->markCompleted();

        $event = new BookingEvent(
            Uuid::v4()->toRfc4122(),
            $booking,
            $manager,
            BookingEventType::COMPLETED,
            $beforeStatus,
            BookingStatus::COMPLETED,
        );
        $this->em->persist($event);
        $this->em->flush();

        $this->auditService->log(
            $orgId,
            $manager,
            $manager->getUsername(),
            AuditActions::BOOKING_COMPLETED,
            'Booking',
            $bookingId,
            ['status' => $beforeStatus->value],
            ['status' => BookingStatus::COMPLETED->value],
        );

        return $booking;
    }

    public function cancel(User $user, string $bookingId): Booking
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $booking = $this->findBookingInOrg($bookingId, $orgId);

        if ($user->getRole() === UserRole::TENANT) {
            if ($booking->getTenantUserId() !== $user->getId()) {
                throw new AccessDeniedException('Booking does not belong to this tenant');
            }
        } else {
            $this->rbac->enforce($user, RbacEnforcer::ACTION_MANAGE_BOOKINGS);
        }

        $currentStatus = $booking->getStatus();
        if ($currentStatus !== BookingStatus::CONFIRMED && $currentStatus !== BookingStatus::ACTIVE) {
            throw new \DomainException('Booking must be CONFIRMED or ACTIVE to cancel');
        }

        $settings = $this->settingsRepository->findByOrganizationId($orgId);
        $cancellationFeePct = $settings !== null ? $settings->getCancellationFeePct() : '20.00';

        $now = $this->orgTimeService->now($orgId);
        $hoursUntilStart = ($booking->getStartAt()->getTimestamp() - $now->getTimestamp()) / 3600;

        $fee = '0.00';
        if ($hoursUntilStart < 24) {
            $fee = bcdiv(
                bcmul($booking->getBaseAmount(), $cancellationFeePct, 4),
                '100',
                2,
            );
        }

        $beforeStatus = $booking->getStatus();
        $booking->markCanceled($fee);

        if (bccomp($fee, '0.00', 2) > 0) {
            $this->billingService->issuePenaltyBill(
                $user,
                $booking,
                $fee,
                'Cancellation fee',
            );
        }

        $event = new BookingEvent(
            Uuid::v4()->toRfc4122(),
            $booking,
            $user,
            BookingEventType::CANCELED,
            $beforeStatus,
            BookingStatus::CANCELED,
            ['cancellation_fee' => $fee],
        );
        $this->em->persist($event);
        $this->em->flush();

        $this->auditService->log(
            $orgId,
            $user,
            $user->getUsername(),
            AuditActions::BOOKING_CANCELED,
            'Booking',
            $bookingId,
            ['status' => $beforeStatus->value],
            ['status' => BookingStatus::CANCELED->value, 'cancellation_fee' => $fee],
        );

        $this->notificationService->createNotification($orgId, $booking->getTenantUserId(), 'booking.canceled', 'Booking Canceled', 'Your booking has been canceled.' . ($fee !== '0.00' ? ' A cancellation fee of ' . $fee . ' has been applied.' : ''));

        return $booking;
    }

    public function markNoShow(User $manager, string $bookingId): Booking
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MARK_NOSHOW);
        $orgId = $this->orgScope->getOrganizationId($manager);
        $booking = $this->findBookingInOrg($bookingId, $orgId);

        $status = $booking->getStatus();
        if ($status !== BookingStatus::ACTIVE && $status !== BookingStatus::CONFIRMED) {
            throw new \DomainException('Booking must be in CONFIRMED or ACTIVE status to mark as no-show');
        }

        if ($booking->getCheckedInAt() !== null) {
            throw new \DomainException('Cannot mark as no-show: guest has already checked in');
        }

        $settings = $this->settingsRepository->findByOrganizationId($orgId);
        $gracePeriodMinutes = $settings !== null ? $settings->getNoShowGracePeriodMinutes() : 30;
        $noShowFeePct = $settings !== null ? $settings->getNoShowFeePct() : '50.00';
        $firstDayRentEnabled = $settings !== null ? $settings->getNoShowFirstDayRentEnabled() : true;

        $graceDeadline = $booking->getStartAt()->modify("+{$gracePeriodMinutes} minutes");
        $now = $this->orgTimeService->now($orgId);

        if ($now < $graceDeadline) {
            throw new \DomainException('Grace period has not yet elapsed');
        }

        $penalty = bcdiv(
            bcmul($booking->getBaseAmount(), $noShowFeePct, 4),
            '100',
            2,
        );

        if ($firstDayRentEnabled) {
            $pricing = $this->pricingService->getActivePricing(
                $booking->getInventoryItemId(),
                $booking->getStartAt(),
            );
            if ($pricing !== null) {
                $dailyRate = match ($pricing->getRateType()) {
                    \App\Enum\RateType::HOURLY => bcmul($pricing->getAmount(), '24', 2),
                    \App\Enum\RateType::DAILY => $pricing->getAmount(),
                    \App\Enum\RateType::MONTHLY => bcdiv($pricing->getAmount(), '30', 2),
                    \App\Enum\RateType::FLAT => $pricing->getAmount(),
                };
                $firstDayRent = bcmul($dailyRate, (string) $booking->getBookedUnits(), 2);
                $penalty = bcadd($penalty, $firstDayRent, 2);
            }
        }

        $beforeStatus = $booking->getStatus();
        $booking->markNoShow($penalty);

        $this->billingService->issuePenaltyBill(
            $manager,
            $booking,
            $penalty,
            'No-show penalty',
        );

        $event = new BookingEvent(
            Uuid::v4()->toRfc4122(),
            $booking,
            $manager,
            BookingEventType::NO_SHOW_MARKED,
            $beforeStatus,
            BookingStatus::NO_SHOW,
            ['penalty_amount' => $penalty],
        );
        $this->em->persist($event);
        $this->em->flush();

        $this->auditService->log(
            $orgId,
            $manager,
            $manager->getUsername(),
            AuditActions::BOOKING_NO_SHOW,
            'Booking',
            $bookingId,
            ['status' => $beforeStatus->value],
            ['status' => BookingStatus::NO_SHOW->value, 'penalty' => $penalty],
        );

        $this->notificationService->createNotification($booking->getOrganizationId(), $booking->getTenantUserId(), 'booking.no_show', 'No-Show Recorded', 'A no-show has been recorded for your booking. A penalty of ' . $penalty . ' has been applied.');

        return $booking;
    }

    public function reschedule(User $user, string $bookingId, string $newHoldId): Booking
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $booking = $this->findBookingInOrg($bookingId, $orgId);

        // RBAC: tenant may reschedule own booking; manager/admin via MANAGE_BOOKINGS
        if ($user->getRole() === UserRole::TENANT) {
            if ($booking->getTenantUserId() !== $user->getId()) {
                throw new AccessDeniedException('Cannot reschedule another tenant\'s booking');
            }
        } else {
            $this->rbac->enforce($user, RbacEnforcer::ACTION_MANAGE_BOOKINGS);
        }

        if ($booking->getStatus() !== BookingStatus::CONFIRMED) {
            throw new \DomainException('Booking must be CONFIRMED to reschedule');
        }

        $newHold = $this->holdService->getHold($user, $newHoldId);

        if ($newHold->getStatus() !== BookingHoldStatus::ACTIVE) {
            throw new \DomainException('New hold must be ACTIVE');
        }

        if ($newHold->getTenantUserId() !== $booking->getTenantUserId()) {
            throw new AccessDeniedException('New hold must belong to the same tenant');
        }

        $oldSourceHold = $booking->getSourceHold();
        if ($oldSourceHold !== null && $oldSourceHold->getStatus() === BookingHoldStatus::CONVERTED) {
            // old hold is already converted, nothing to release
        }

        $newHold->transitionTo(BookingHoldStatus::CONVERTED);
        $newHold->setConfirmedBookingId($bookingId);

        $beforeStartAt = $booking->getStartAt()->format('c');
        $beforeEndAt = $booking->getEndAt()->format('c');

        $booking->setStartAt($newHold->getStartAt());
        $booking->setEndAt($newHold->getEndAt());

        $event = new BookingEvent(
            Uuid::v4()->toRfc4122(),
            $booking,
            $user,
            BookingEventType::RESCHEDULED,
            BookingStatus::CONFIRMED,
            BookingStatus::CONFIRMED,
            [
                'old_start_at' => $beforeStartAt,
                'old_end_at' => $beforeEndAt,
                'new_start_at' => $newHold->getStartAt()->format('c'),
                'new_end_at' => $newHold->getEndAt()->format('c'),
                'new_hold_id' => $newHoldId,
            ],
        );
        $this->em->persist($event);
        $this->em->flush();

        $this->auditService->log(
            $orgId,
            $user,
            $user->getUsername(),
            AuditActions::BOOKING_RESCHEDULED,
            'Booking',
            $bookingId,
            ['start_at' => $beforeStartAt, 'end_at' => $beforeEndAt],
            ['start_at' => $newHold->getStartAt()->format('c'), 'end_at' => $newHold->getEndAt()->format('c')],
        );

        return $booking;
    }

    public function getBooking(User $user, string $bookingId): Booking
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $booking = $this->findBookingInOrg($bookingId, $orgId);

        if ($user->getRole() === UserRole::TENANT && $booking->getTenantUserId() !== $user->getId()) {
            throw new AccessDeniedException('Booking does not belong to this tenant');
        }

        return $booking;
    }

    public function listBookings(User $user, array $filters, int $page, int $perPage): array
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $perPage = min($perPage, 100);

        if ($user->getRole() === UserRole::TENANT) {
            $filters['tenant_user_id'] = $user->getId();
        }

        $items = $this->bookingRepository->findByOrg($orgId, $filters, $page, $perPage);
        $total = $this->bookingRepository->countByOrg($orgId, $filters);

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_next' => ($page * $perPage) < $total,
            ],
        ];
    }

    public function evaluateNoShows(): int
    {
        $count = 0;
        $bookings = $this->bookingRepository->findNoShowCandidates();

        foreach ($bookings as $booking) {
            try {
                $this->em->wrapInTransaction(function () use ($booking, &$count) {
                    $orgId = $booking->getOrganizationId();
                    $settings = $this->settingsRepository->findByOrganizationId($orgId);
                    $gracePeriodMinutes = $settings !== null ? $settings->getNoShowGracePeriodMinutes() : 30;
                    $noShowFeePct = $settings !== null ? $settings->getNoShowFeePct() : '50.00';
                    $firstDayRentEnabled = $settings !== null ? $settings->getNoShowFirstDayRentEnabled() : true;

                    $graceDeadline = $booking->getStartAt()->modify("+{$gracePeriodMinutes} minutes");
                    $now = $this->orgTimeService->now($orgId);

                    if ($now < $graceDeadline) {
                        return;
                    }

                    $penalty = bcdiv(
                        bcmul($booking->getBaseAmount(), $noShowFeePct, 4),
                        '100',
                        2,
                    );

                    if ($firstDayRentEnabled) {
                        $pricing = $this->pricingService->getActivePricing(
                            $booking->getInventoryItemId(),
                            $booking->getStartAt(),
                        );
                        if ($pricing !== null) {
                            $dailyRate = match ($pricing->getRateType()) {
                                \App\Enum\RateType::HOURLY => bcmul($pricing->getAmount(), '24', 2),
                                \App\Enum\RateType::DAILY => $pricing->getAmount(),
                                \App\Enum\RateType::MONTHLY => bcdiv($pricing->getAmount(), '30', 2),
                                \App\Enum\RateType::FLAT => $pricing->getAmount(),
                            };
                            $firstDayRent = bcmul($dailyRate, (string) $booking->getBookedUnits(), 2);
                            $penalty = bcadd($penalty, $firstDayRent, 2);
                        }
                    }

                    $beforeStatus = $booking->getStatus();
                    $booking->markNoShow($penalty);

                    // Use tenant user as event actor for automated no-show (no authenticated user in background jobs)
                    $actorUser = $booking->getTenantUser();
                    $this->billingService->issuePenaltyBill(
                        $actorUser,
                        $booking,
                        $penalty,
                        'Automated no-show penalty',
                    );

                    $event = new BookingEvent(
                        Uuid::v4()->toRfc4122(),
                        $booking,
                        $actorUser,
                        BookingEventType::NO_SHOW_MARKED,
                        $beforeStatus,
                        BookingStatus::NO_SHOW,
                        ['penalty_amount' => $penalty, 'automated' => true],
                    );
                    $this->em->persist($event);

                    $this->auditService->log(
                        $orgId,
                        null,
                        'system:no_show_evaluator',
                        AuditActions::BOOKING_NO_SHOW,
                        'Booking',
                        $booking->getId(),
                        ['status' => BookingStatus::ACTIVE->value],
                        ['status' => BookingStatus::NO_SHOW->value, 'penalty' => $penalty, 'automated' => true],
                    );

                    $this->notificationService->createNotification(
                        $orgId,
                        $booking->getTenantUserId(),
                        'booking.no_show',
                        'No-Show Recorded',
                        'A no-show has been recorded for your booking. A penalty of ' . $penalty . ' has been applied.',
                    );

                    $count++;
                });
            } catch (\Throwable) {
                // Per-booking isolation: one failure does not halt others
                continue;
            }
        }

        return $count;
    }

    private function findBookingInOrg(string $bookingId, string $orgId): Booking
    {
        $booking = $this->bookingRepository->findByIdAndOrg($bookingId, $orgId);

        if ($booking === null) {
            throw new EntityNotFoundException('Booking', $bookingId);
        }

        return $booking;
    }
}
