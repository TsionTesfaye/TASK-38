<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Repository\DeviceSessionRepository;
use App\Repository\UserRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Validation\EnumValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class UserService
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly DeviceSessionRepository $deviceSessionRepository,
        private readonly EntityManagerInterface $em,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
        private readonly AuditService $auditService,
    ) {}

    public function createUser(
        User $admin,
        string $username,
        string $password,
        string $displayName,
        string $role,
    ): User {
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_USERS);
        $org = $admin->getOrganization();

        $existing = $this->userRepo->findByUsername($username);
        if ($existing !== null) {
            throw new \DomainException('Username already exists');
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $user = new User(
            Uuid::v4()->toRfc4122(),
            $org,
            $username,
            $passwordHash,
            $displayName,
            EnumValidator::validate($role, UserRole::class, 'role'),
        );
        $this->em->persist($user);
        $this->em->flush();

        $this->auditService->log(
            $org->getId(),
            $admin,
            $admin->getUsername(),
            'USER_CREATED',
            'User',
            $user->getId(),
        );

        return $user;
    }

    public function updateUser(User $admin, string $userId, array $data): User
    {
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_USERS);
        $orgId = $this->orgScope->getOrganizationId($admin);
        $user = $this->userRepo->findByIdAndOrg($userId, $orgId);
        if (!$user) {
            throw new EntityNotFoundException('User', $userId);
        }

        if (isset($data['display_name'])) {
            $user->setDisplayName($data['display_name']);
        }
        $passwordChanged = false;
        if (isset($data['password'])) {
            $user->setPasswordHash(password_hash($data['password'], PASSWORD_BCRYPT));
            $passwordChanged = true;
        }
        if (isset($data['is_active'])) {
            $user->setIsActive($data['is_active']);
        }

        $this->em->flush();

        // Forced logout: revoke all active sessions when password is changed.
        // This matches the self-service flow in AuthService::changePassword().
        if ($passwordChanged) {
            $this->deviceSessionRepository->revokeAllByUserId($user->getId());
        }

        $this->auditService->log(
            $orgId,
            $admin,
            $admin->getUsername(),
            $passwordChanged ? 'USER_PASSWORD_RESET' : 'USER_UPDATED',
            'User',
            $user->getId(),
        );

        return $user;
    }

    public function freezeUser(User $admin, string $userId): User
    {
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_USERS);
        $orgId = $this->orgScope->getOrganizationId($admin);
        $user = $this->userRepo->findByIdAndOrg($userId, $orgId);
        if (!$user) {
            throw new EntityNotFoundException('User', $userId);
        }

        if ($user->getId() === $admin->getId()) {
            throw new \DomainException('Cannot freeze your own account');
        }

        $user->setIsFrozen(true);
        $this->em->flush();

        $this->auditService->log(
            $orgId,
            $admin,
            $admin->getUsername(),
            'USER_FROZEN',
            'User',
            $user->getId(),
        );

        return $user;
    }

    public function unfreezeUser(User $admin, string $userId): User
    {
        $this->rbac->enforce($admin, RbacEnforcer::ACTION_MANAGE_USERS);
        $orgId = $this->orgScope->getOrganizationId($admin);
        $user = $this->userRepo->findByIdAndOrg($userId, $orgId);
        if (!$user) {
            throw new EntityNotFoundException('User', $userId);
        }

        $user->setIsFrozen(false);
        $this->em->flush();

        $this->auditService->log(
            $orgId,
            $admin,
            $admin->getUsername(),
            'USER_UNFROZEN',
            'User',
            $user->getId(),
        );

        return $user;
    }

    public function getUser(User $requester, string $userId): User
    {
        $orgId = $this->orgScope->getOrganizationId($requester);

        if ($requester->getId() === $userId) {
            return $requester;
        }

        $this->rbac->enforce($requester, RbacEnforcer::ACTION_VIEW_ORG);
        $user = $this->userRepo->findByIdAndOrg($userId, $orgId);
        if (!$user) {
            throw new EntityNotFoundException('User', $userId);
        }

        return $user;
    }

    public function getCurrentUser(User $user): User
    {
        return $user;
    }

    public function listUsers(User $requester, array $filters, int $page, int $perPage): array
    {
        $this->rbac->enforce($requester, RbacEnforcer::ACTION_VIEW_ORG);
        $orgId = $this->orgScope->getOrganizationId($requester);
        $perPage = min($perPage, 100);

        $items = $this->userRepo->findByOrganizationId($orgId, $filters, $page, $perPage);
        $total = $this->userRepo->countByOrganizationId($orgId, $filters);

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
