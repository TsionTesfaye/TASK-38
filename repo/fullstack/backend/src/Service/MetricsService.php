<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Metrics\MetricsCollector;
use App\Repository\NotificationRepository;
use App\Repository\TerminalPackageTransferRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;

class MetricsService
{
    public function __construct(
        private readonly MetricsCollector $metricsCollector,
        private readonly NotificationRepository $notificationRepo,
        private readonly TerminalPackageTransferRepository $transferRepo,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
    ) {}

    /**
     * Returns metrics scoped to the authenticated user's organization.
     * Queue depths reflect only this org's pending items, not system-wide totals.
     */
    public function getMetricsSummary(User $user): array
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_AUDIT);
        $orgId = $this->orgScope->getOrganizationId($user);

        $this->metricsCollector->setNotificationQueueDepth(
            $this->notificationRepo->countPendingDueByOrg($orgId),
        );
        $this->metricsCollector->setTransferQueueDepth(
            $this->transferRepo->countIncompleteByOrg($orgId),
        );

        return $this->metricsCollector->getSummary();
    }
}
