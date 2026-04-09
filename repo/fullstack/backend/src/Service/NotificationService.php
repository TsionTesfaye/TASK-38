<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationStatus;
use App\Exception\EntityNotFoundException;
use App\Repository\NotificationRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifRepo,
        private readonly NotificationPreferenceRepository $prefRepo,
        private readonly SettingsRepository $settingsRepo,
        private readonly EntityManagerInterface $em,
        private readonly OrganizationScope $orgScope,
        private readonly OrgTimeService $orgTimeService,
    ) {}

    public function createNotification(string $orgId, string $userId, string $eventCode, string $title, string $body): Notification
    {
        $user = $this->em->find(User::class, $userId);
        if ($user === null || $user->getOrganizationId() !== $orgId) {
            throw new \App\Exception\EntityNotFoundException('User', $userId);
        }

        // Use admin-configured template if available, otherwise fall back to caller-provided body
        $settings = $this->settingsRepo->findByOrganizationId($orgId);
        if ($settings !== null) {
            $template = $settings->getNotificationTemplate($eventCode);
            if ($template !== null && $template !== '') {
                $body = $template;
            }
        }
        $org = $user->getOrganization();

        $pref = $this->prefRepo->findByUserAndEvent($userId, $eventCode);
        if ($pref !== null && !$pref->isEnabled()) {
            return new Notification(Uuid::v4()->toRfc4122(), $org, $user, $eventCode, $title, $body, $this->orgTimeService->now($orgId));
        }

        $dndStart = $pref ? $pref->getDndStartLocal() : '21:00';
        $dndEnd = $pref ? $pref->getDndEndLocal() : '08:00';
        $now = $this->orgTimeService->now($orgId);
        $currentTime = $this->orgTimeService->getCurrentLocalTime($orgId);

        $scheduledFor = $now;
        if ($this->isInDndWindow($dndStart, $dndEnd, $currentTime)) {
            $endHour = (int)substr($dndEnd, 0, 2);
            $endMin = (int)substr($dndEnd, 3, 2);
            $scheduledFor = $now->setTime($endHour, $endMin);
            if ($scheduledFor <= $now) {
                $scheduledFor = $scheduledFor->modify('+1 day');
            }
        }

        $notification = new Notification(Uuid::v4()->toRfc4122(), $org, $user, $eventCode, $title, $body, $scheduledFor);
        if (!$this->isInDndWindow($dndStart, $dndEnd, $currentTime)) {
            $notification->markDelivered();
        }
        $this->em->persist($notification);
        $this->em->flush();
        return $notification;
    }

    public function deliverPendingNotifications(): int
    {
        $pending = $this->notifRepo->findPendingDue();
        $count = 0;
        foreach ($pending as $notification) {
            $notification->markDelivered();
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    public function markRead(User $user, string $notificationId): Notification
    {
        $notification = $this->notifRepo->findByIdAndUser($notificationId, $user->getId());
        if (!$notification) { throw new EntityNotFoundException('Notification', $notificationId); }
        if ($notification->getStatus() !== NotificationStatus::DELIVERED) {
            throw new \App\Exception\InvalidStateTransitionException($notification->getStatus()->value, NotificationStatus::READ->value);
        }
        $notification->markRead();
        $this->em->flush();
        return $notification;
    }

    public function listNotifications(User $user, int $page, int $perPage): array
    {
        $perPage = min($perPage, 100);
        $items = $this->notifRepo->findByUser($user->getId(), $page, $perPage);
        $total = $this->notifRepo->countByUser($user->getId());

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

    public function updatePreference(User $user, string $eventCode, bool $enabled, ?string $dndStart, ?string $dndEnd): NotificationPreference
    {
        $pref = $this->prefRepo->findByUserAndEvent($user->getId(), $eventCode);
        if (!$pref) {
            $pref = new NotificationPreference(Uuid::v4()->toRfc4122(), $user, $eventCode);
            $this->em->persist($pref);
        }
        $pref->setIsEnabled($enabled);
        if ($dndStart !== null) { $pref->setDndStartLocal($dndStart); }
        if ($dndEnd !== null) { $pref->setDndEndLocal($dndEnd); }
        $this->em->flush();
        return $pref;
    }

    public function getPreferences(User $user): array
    {
        return $this->prefRepo->findAllByUser($user->getId());
    }

    public function isInDndWindow(string $startLocal, string $endLocal, string $currentTimeLocal): bool
    {
        if ($startLocal === $endLocal) { return false; }
        if ($startLocal > $endLocal) {
            return $currentTimeLocal >= $startLocal || $currentTimeLocal < $endLocal;
        }
        return $currentTimeLocal >= $startLocal && $currentTimeLocal < $endLocal;
    }
}
