<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditActions;
use App\Entity\Booking;
use App\Entity\BookingEvent;
use App\Entity\BookingHold;
use App\Entity\User;
use App\Enum\BookingEventType;
use App\Enum\BookingHoldStatus;
use App\Enum\BookingStatus;
use App\Enum\LedgerEntryType;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\BookingDurationExceededException;
use App\Exception\DuplicateRequestException;
use App\Exception\EntityNotFoundException;
use App\Exception\HoldExpiredException;
use App\Exception\InsufficientCapacityException;
use App\Repository\BookingHoldRepository;
use App\Repository\InventoryItemRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class BookingHoldService
{
    public function __construct(
        private readonly BookingHoldRepository $holdRepository,
        private readonly InventoryItemRepository $inventoryItemRepository,
        private readonly PricingService $pricingService,
        private readonly IdempotencyService $idempotencyService,
        private readonly ThrottleService $throttleService,
        private readonly SettingsRepository $settingsRepository,
        private readonly BillingService $billingService,
        private readonly LedgerService $ledgerService,
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
        private readonly OrganizationScope $orgScope,
        private readonly EntityManagerInterface $em,
    ) {}

    public function createHold(
        User $tenant,
        string $inventoryItemId,
        int $units,
        \DateTimeImmutable $startAt,
        \DateTimeImmutable $endAt,
        string $requestKey,
    ): BookingHold {
        if ($tenant->getRole() !== UserRole::TENANT) {
            throw new AccessDeniedException('Only tenants can create holds');
        }

        $existing = $this->idempotencyService->check($tenant->getId(), $requestKey);
        if ($existing !== null) {
            throw new DuplicateRequestException($existing);
        }

        $orgId = $this->orgScope->getOrganizationId($tenant);
        $settings = $this->settingsRepository->findByOrganizationId($orgId);

        $item = $this->inventoryItemRepository->findByIdAndOrg($inventoryItemId, $orgId);
        if ($item === null) {
            throw new EntityNotFoundException('InventoryItem', $inventoryItemId);
        }

        if (!$item->isActive()) {
            throw new \DomainException('Inventory item is not active');
        }

        $maxDays = $settings !== null ? $settings->getMaxBookingDurationDays() : 365;
        $durationDays = $startAt->diff($endAt)->days;
        if ($durationDays > $maxDays) {
            throw new BookingDurationExceededException();
        }

        $holdDuration = $settings !== null ? $settings->getHoldDurationMinutes() : 10;
        $throttleLimit = $settings !== null ? $settings->getBookingAttemptsPerItemPerMinute() : 30;

        // Record attempt and enforce throttle BEFORE the main transaction.
        // This ensures every request — including those that fail validation,
        // capacity checks, or any other downstream error — counts toward
        // the rate limit.
        $this->throttleService->checkAndRecord($inventoryItemId, $throttleLimit);

        // ATOMIC: lock + validate capacity + create hold in single transaction
        $hold = $this->em->wrapInTransaction(function () use ($tenant, $item, $inventoryItemId, $units, $startAt, $endAt, $requestKey, $holdDuration) {
            $conn = $this->em->getConnection();
            $conn->executeStatement(
                'SELECT id FROM inventory_items WHERE id = ? FOR UPDATE',
                [$inventoryItemId]
            );

            // Hardening: mark time-expired holds before the capacity check so
            // they can never be counted regardless of query filters.
            $conn->executeStatement(
                'UPDATE booking_holds SET status = ? WHERE inventory_item_id = ? AND status = ? AND expires_at <= NOW()',
                [BookingHoldStatus::EXPIRED->value, $inventoryItemId, BookingHoldStatus::ACTIVE->value],
            );

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $activeHeldUnits = (int) $conn->fetchOne(
                'SELECT COALESCE(SUM(held_units), 0) FROM booking_holds WHERE inventory_item_id = ? AND status = ? AND expires_at > ? AND start_at < ? AND end_at > ?',
                [$inventoryItemId, BookingHoldStatus::ACTIVE->value, $now, $endAt->format('Y-m-d H:i:s'), $startAt->format('Y-m-d H:i:s')]
            );

            $activeBookedUnits = (int) $conn->fetchOne(
                'SELECT COALESCE(SUM(booked_units), 0) FROM bookings WHERE inventory_item_id = ? AND status IN (?, ?) AND start_at < ? AND end_at > ?',
                [$inventoryItemId, BookingStatus::CONFIRMED->value, BookingStatus::ACTIVE->value, $endAt->format('Y-m-d H:i:s'), $startAt->format('Y-m-d H:i:s')]
            );

            $available = $item->getTotalCapacity() - $activeHeldUnits - $activeBookedUnits;

            if ($units > $available) {
                throw new InsufficientCapacityException();
            }

            $expiresAt = new \DateTimeImmutable("+{$holdDuration} minutes");

            $newHold = new BookingHold(
                Uuid::v4()->toRfc4122(),
                $tenant->getOrganization(),
                $item,
                $tenant,
                $requestKey,
                $units,
                $startAt,
                $endAt,
                $expiresAt,
            );

            $this->em->persist($newHold);
            return $newHold;
        });

        $this->idempotencyService->store($tenant, $requestKey, ['hold_id' => $hold->getId()]);

        $this->auditService->log(
            $orgId,
            $tenant,
            $tenant->getUsername(),
            AuditActions::HOLD_CREATED,
            'BookingHold',
            $hold->getId(),
            null,
            [
                'inventory_item_id' => $inventoryItemId,
                'units' => $units,
                'start_at' => $startAt->format('c'),
                'end_at' => $endAt->format('c'),
                'expires_at' => $hold->getExpiresAt()->format('c'),
            ],
        );

        return $hold;
    }

    public function confirmHold(User $tenant, string $holdId, string $requestKey): Booking
    {
        $existing = $this->idempotencyService->check($tenant->getId(), $requestKey);
        if ($existing !== null) {
            throw new DuplicateRequestException($existing);
        }

        $orgId = $this->orgScope->getOrganizationId($tenant);

        // Wrap in transaction with row-level lock to prevent concurrent double-confirm.
        $booking = $this->em->wrapInTransaction(function () use ($tenant, $holdId, $orgId, $requestKey) {
            $conn = $this->em->getConnection();

            // Acquire exclusive lock on the hold row — a concurrent confirm will block here.
            $row = $conn->fetchAssociative(
                'SELECT id, status, expires_at, tenant_user_id FROM booking_holds WHERE id = ? AND organization_id = ? FOR UPDATE',
                [$holdId, $orgId],
            );

            if ($row === false) {
                throw new EntityNotFoundException('BookingHold', $holdId);
            }

            if ($row['tenant_user_id'] !== $tenant->getId()) {
                throw new AccessDeniedException('Hold does not belong to this tenant');
            }

            if ($row['status'] !== 'active') {
                throw new \DomainException('Hold is not active');
            }

            $expiresAt = new \DateTimeImmutable($row['expires_at']);
            if ($expiresAt < new \DateTimeImmutable()) {
                throw new HoldExpiredException();
            }

            // Re-fetch the managed entity now that we hold the lock.
            $hold = $this->holdRepository->findByIdAndOrg($holdId, $orgId);
            $hold->transitionTo(BookingHoldStatus::CONVERTED);

            $amount = $this->pricingService->calculateBookingAmount(
                $hold->getInventoryItemId(),
                $hold->getStartAt(),
                $hold->getEndAt(),
                $hold->getHeldUnits(),
            );

            $currency = $hold->getOrganization()->getDefaultCurrency();

            $bookingId = Uuid::v4()->toRfc4122();
            $booking = new Booking(
                $bookingId,
                $hold->getOrganization(),
                $hold->getInventoryItem(),
                $tenant,
                $hold,
                $hold->getStartAt(),
                $hold->getEndAt(),
                $hold->getHeldUnits(),
                $currency,
                $amount,
                $amount,
            );

            $hold->setConfirmedBookingId($bookingId);

            $this->em->persist($booking);

            // issueInitialBill creates the bill AND its BILL_ISSUED ledger entry atomically.
            // No additional ledger write here — single source of truth is BillingService.
            $this->billingService->issueInitialBill($tenant, $booking);

            $event = new BookingEvent(
                Uuid::v4()->toRfc4122(),
                $booking,
                $tenant,
                BookingEventType::HOLD_CONVERTED,
                null,
                BookingStatus::CONFIRMED,
                ['hold_id' => $holdId],
            );
            $this->em->persist($event);

            $this->idempotencyService->store($tenant, $requestKey, ['booking_id' => $booking->getId()]);

            return $booking;
        });

        // Post-commit: fire-and-forget audit + notification (failures don't undo the booking)
        try {
            $this->auditService->log(
                $orgId,
                $tenant,
                $tenant->getUsername(),
                AuditActions::HOLD_CONFIRMED,
                'BookingHold',
                $holdId,
                null,
                ['booking_id' => $booking->getId(), 'amount' => $booking->getFinalAmount()],
            );
        } catch (\Throwable) {}

        try {
            $this->notificationService->createNotification(
                $orgId,
                $tenant->getId(),
                'booking.confirmed',
                'Booking Confirmed',
                'Your booking has been confirmed for ' . $booking->getStartAt()->format('Y-m-d H:i'),
            );
        } catch (\Throwable) {}

        return $booking;
    }

    public function releaseHold(User $user, string $holdId): void
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $hold = $this->holdRepository->findByIdAndOrg($holdId, $orgId);

        if ($hold === null) {
            throw new EntityNotFoundException('BookingHold', $holdId);
        }

        // Tenant users may only release their own holds
        if ($user->getRole() === UserRole::TENANT && $hold->getTenantUserId() !== $user->getId()) {
            throw new AccessDeniedException('Hold does not belong to this tenant');
        }

        if ($hold->getStatus() !== BookingHoldStatus::ACTIVE) {
            throw new \DomainException('Hold is not active');
        }

        $hold->transitionTo(BookingHoldStatus::RELEASED);
        $this->em->flush();

        $this->auditService->log(
            $orgId,
            $user,
            $user->getUsername(),
            AuditActions::HOLD_RELEASED,
            'BookingHold',
            $holdId,
        );
    }

    public function expireHolds(): int
    {
        $expiredHolds = $this->holdRepository->findExpiredActive();
        $count = 0;

        foreach ($expiredHolds as $hold) {
            try {
                $this->em->wrapInTransaction(function () use ($hold) {
                    $hold->transitionTo(BookingHoldStatus::EXPIRED);
                    $this->em->flush();
                });
                $count++;
            } catch (\Throwable) {
                // Per-hold isolation: failure on one hold does not halt others
                continue;
            }
        }

        return $count;
    }

    public function getHold(User $user, string $holdId): BookingHold
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $hold = $this->holdRepository->findByIdAndOrg($holdId, $orgId);

        if ($hold === null) {
            throw new EntityNotFoundException('BookingHold', $holdId);
        }

        if ($user->getRole() === UserRole::TENANT && $hold->getTenantUserId() !== $user->getId()) {
            throw new AccessDeniedException('Hold does not belong to this tenant');
        }

        return $hold;
    }
}
