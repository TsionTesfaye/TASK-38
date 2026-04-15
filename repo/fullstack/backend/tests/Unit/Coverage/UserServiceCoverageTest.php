<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Repository\DeviceSessionRepository;
use App\Repository\UserRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserServiceCoverageTest extends TestCase
{
    private function makeService(
        ?UserRepository $userRepo = null,
        ?DeviceSessionRepository $deviceSessionRepo = null,
        ?EntityManagerInterface $em = null,
    ): UserService {
        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        return new UserService(
            $userRepo ?? $this->createMock(UserRepository::class),
            $deviceSessionRepo ?? $this->createMock(DeviceSessionRepository::class),
            $em ?? $this->createMock(EntityManagerInterface::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
        );
    }

    private function makeAdmin(string $orgId = 'org-1'): User&MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn($orgId);

        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn('admin-1');
        $admin->method('getRole')->willReturn(UserRole::ADMINISTRATOR);
        $admin->method('getOrganization')->willReturn($org);
        $admin->method('getOrganizationId')->willReturn($orgId);
        $admin->method('getUsername')->willReturn('admin');
        return $admin;
    }

    public function testCreateUserSuccess(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($userRepo, null, $em);
        $admin = $this->makeAdmin();

        $user = $service->createUser($admin, 'newuser', 'password123', 'New User', 'tenant');
        $this->assertInstanceOf(User::class, $user);
    }

    public function testCreateUserDuplicateUsername(): void
    {
        $existing = $this->createMock(User::class);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($existing);

        $service = $this->makeService($userRepo);
        $admin = $this->makeAdmin();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Username already exists');
        $service->createUser($admin, 'taken', 'password123', 'X', 'tenant');
    }

    public function testCreateUserInvalidRole(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn(null);

        $service = $this->makeService($userRepo);
        $admin = $this->makeAdmin();

        $this->expectException(\App\Exception\InvalidEnumException::class);
        $service->createUser($admin, 'u', 'password123', 'X', 'not_a_real_role');
    }

    public function testCreateUserDeniedForNonAdmin(): void
    {
        $service = $this->makeService();
        $tenant = $this->createMock(User::class);
        $tenant->method('getId')->willReturn('t-1');
        $tenant->method('getRole')->willReturn(UserRole::TENANT);

        $this->expectException(AccessDeniedException::class);
        $service->createUser($tenant, 'u', 'password123', 'X', 'tenant');
    }

    public function testUpdateUserDisplayName(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $target = $this->createMock(User::class);
        $target->method('getId')->willReturn('target-1');
        $target->method('getOrganization')->willReturn($org);
        $target->method('getOrganizationId')->willReturn('org-1');
        $target->expects($this->once())->method('setDisplayName')->with('New Name');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByIdAndOrg')->willReturn($target);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($userRepo, null, $em);
        $admin = $this->makeAdmin();

        $service->updateUser($admin, 'target-1', ['display_name' => 'New Name']);
    }

    public function testUpdateUserNotFound(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByIdAndOrg')->willReturn(null);

        $service = $this->makeService($userRepo);
        $admin = $this->makeAdmin();

        $this->expectException(EntityNotFoundException::class);
        $service->updateUser($admin, 'nope', ['display_name' => 'X']);
    }

    public function testFreezeUser(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $target = $this->createMock(User::class);
        $target->method('getId')->willReturn('target-1');
        $target->method('getOrganization')->willReturn($org);
        $target->method('getOrganizationId')->willReturn('org-1');
        $target->method('getUsername')->willReturn('target');
        $target->expects($this->once())->method('setIsFrozen')->with(true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByIdAndOrg')->willReturn($target);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($userRepo, null, $em);
        $admin = $this->makeAdmin();

        $service->freezeUser($admin, 'target-1');
    }

    public function testUnfreezeUser(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $target = $this->createMock(User::class);
        $target->method('getId')->willReturn('target-1');
        $target->method('getOrganization')->willReturn($org);
        $target->method('getOrganizationId')->willReturn('org-1');
        $target->method('getUsername')->willReturn('target');
        $target->expects($this->once())->method('setIsFrozen')->with(false);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByIdAndOrg')->willReturn($target);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($userRepo, null, $em);
        $admin = $this->makeAdmin();

        $service->unfreezeUser($admin, 'target-1');
    }

    public function testGetCurrentUserReturnsSame(): void
    {
        $service = $this->makeService();
        $user = $this->createMock(User::class);
        $this->assertSame($user, $service->getCurrentUser($user));
    }

    public function testGetUserNotFound(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByIdAndOrg')->willReturn(null);

        $service = $this->makeService($userRepo);
        $admin = $this->makeAdmin();

        $this->expectException(EntityNotFoundException::class);
        $service->getUser($admin, 'not-found');
    }

    public function testListUsersReturnsPaginatedShape(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByOrganizationId')->willReturn([]);
        $userRepo->method('countByOrganizationId')->willReturn(0);

        $service = $this->makeService($userRepo);
        $admin = $this->makeAdmin();

        $result = $service->listUsers($admin, [], 1, 25);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('total', $result['meta']);
    }

    public function testUpdateUserPasswordRevokesSessions(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $target = $this->createMock(User::class);
        $target->method('getId')->willReturn('target-1');
        $target->method('getOrganization')->willReturn($org);
        $target->method('getOrganizationId')->willReturn('org-1');
        $target->expects($this->once())->method('setPasswordHash');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByIdAndOrg')->willReturn($target);

        $deviceRepo = $this->createMock(DeviceSessionRepository::class);
        $deviceRepo->expects($this->once())->method('revokeAllByUserId')->with('target-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($userRepo, $deviceRepo, $em);
        $admin = $this->makeAdmin();

        $service->updateUser($admin, 'target-1', ['password' => 'new_password_123']);
    }
}
