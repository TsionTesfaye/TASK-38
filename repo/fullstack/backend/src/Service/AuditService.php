<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Audit logging service.
 *
 * Security controls:
 *   - Storage: full IDs and payloads are stored in the database for forensic use.
 *   - Serialisation: AuditLog::jsonSerialize() masks objectId to last 4 characters;
 *     before/after JSON payloads are excluded from the API response.
 *   - Access control: listLogs() enforces ACTION_VIEW_AUDIT, granted only to the
 *     administrator role. Tenants, property managers, and finance clerks cannot
 *     read audit logs.
 */
class AuditService
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EntityManagerInterface $em,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
    ) {}

    public function log(
        string $orgId,
        ?User $actor,
        string $actorUsername,
        string $actionCode,
        string $objectType,
        string $objectId,
        ?array $before = null,
        ?array $after = null,
        ?string $clientDeviceId = null,
    ): void {
        $org = $this->em->getReference(\App\Entity\Organization::class, $orgId);
        $entry = new AuditLog(
            Uuid::v4()->toRfc4122(),
            $org,
            $actor,
            $actorUsername,
            $actionCode,
            $objectType,
            $objectId,
            $before,
            $after,
            $clientDeviceId,
        );
        $this->em->persist($entry);
        $this->em->flush();
    }

    public function listLogs(User $user, array $filters, int $page, int $perPage): array
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_AUDIT);
        $orgId = $this->orgScope->getOrganizationId($user);
        $perPage = min($perPage, 100);

        $items = $this->auditLogRepository->findByOrg($orgId, $filters, $page, $perPage);
        $total = $this->auditLogRepository->countByOrg($orgId, $filters);

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
}
