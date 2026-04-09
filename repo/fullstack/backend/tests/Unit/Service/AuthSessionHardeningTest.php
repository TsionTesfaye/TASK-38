<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Organization;
use App\Entity\Settings;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Repository\DeviceSessionRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Auth + session hardening:
 *   1. Admin password reset forces logout (revokes all sessions)
 *   2. Self password change forces logout
 *   3. Non-password update preserves sessions
 *   4. Device cap strictly 5
 *   5. Settings rejects max_devices_per_user > 5
 */
class AuthSessionHardeningTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // 1. ADMIN PASSWORD RESET → FORCED LOGOUT
    // ═══════════════════════════════════════════════════════════════

    private function makeUserService(): array
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $targetUser = $this->createMock(User::class);
        $targetUser->method('getId')->willReturn('target-user-1');
        $targetUser->method('getOrganization')->willReturn($org);
        $targetUser->method('getOrganizationId')->willReturn('org-1');

        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn('admin-1');
        $admin->method('getRole')->willReturn(UserRole::ADMINISTRATOR);
        $admin->method('getOrganization')->willReturn($org);
        $admin->method('getOrganizationId')->willReturn('org-1');
        $admin->method('getUsername')->willReturn('admin');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByIdAndOrg')->willReturn($targetUser);

        $deviceSessionRepo = $this->createMock(DeviceSessionRepository::class);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $service = new UserService(
            $userRepo,
            $deviceSessionRepo,
            $this->createMock(EntityManagerInterface::class),
            $orgScope,
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
        );

        return compact('service', 'admin', 'targetUser', 'deviceSessionRepo');
    }

    public function testAdminPasswordResetRevokesAllTargetSessions(): void
    {
        $m = $this->makeUserService();

        // Password is being changed → revokeAllByUserId MUST be called for target user
        $m['deviceSessionRepo']->expects($this->once())
            ->method('revokeAllByUserId')
            ->with('target-user-1');

        $m['targetUser']->expects($this->once())
            ->method('setPasswordHash');

        $m['service']->updateUser($m['admin'], 'target-user-1', [
            'password' => 'new_secure_password',
        ]);
    }

    public function testNonPasswordUpdatePreservesSessions(): void
    {
        $m = $this->makeUserService();

        // No password change → revokeAllByUserId must NOT be called
        $m['deviceSessionRepo']->expects($this->never())
            ->method('revokeAllByUserId');

        $m['service']->updateUser($m['admin'], 'target-user-1', [
            'display_name' => 'New Display Name',
        ]);
    }

    public function testAdminPasswordResetWithDisplayNameStillRevokes(): void
    {
        $m = $this->makeUserService();

        // Mixed update: password + display_name → must still revoke
        $m['deviceSessionRepo']->expects($this->once())
            ->method('revokeAllByUserId')
            ->with('target-user-1');

        $m['service']->updateUser($m['admin'], 'target-user-1', [
            'display_name' => 'Updated',
            'password' => 'changed_password',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. SELF PASSWORD CHANGE → FORCED LOGOUT
    // ═══════════════════════════════════════════════════════════════

    public function testSelfPasswordChangeRevokesAllSessions(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn('testuser');
        $user->method('getPasswordHash')->willReturn(password_hash('current', PASSWORD_BCRYPT));

        $deviceSessionRepo = $this->createMock(DeviceSessionRepository::class);
        $deviceSessionRepo->expects($this->once())
            ->method('revokeAllByUserId')
            ->with('user-1');

        $em = $this->createMock(EntityManagerInterface::class);

        // Build AuthService with minimal mocks
        $authService = new AuthService(
            $this->createMock(UserRepository::class),
            $deviceSessionRepo,
            $this->createMock(SettingsRepository::class),
            $this->createMock(\App\Security\JwtTokenManager::class),
            $this->createMock(AuditService::class),
            $em,
        );

        // changePassword verifies current, sets new, then revokes all
        $authService->changePassword($user, 'current', 'new_password_123');
    }

    public function testSelfPasswordChangeFailsOnWrongCurrentPassword(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getPasswordHash')->willReturn(password_hash('real_password', PASSWORD_BCRYPT));

        $deviceSessionRepo = $this->createMock(DeviceSessionRepository::class);
        // Must NOT revoke on failed password change
        $deviceSessionRepo->expects($this->never())->method('revokeAllByUserId');

        $authService = new AuthService(
            $this->createMock(UserRepository::class),
            $deviceSessionRepo,
            $this->createMock(SettingsRepository::class),
            $this->createMock(\App\Security\JwtTokenManager::class),
            $this->createMock(AuditService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $this->expectException(AuthenticationException::class);
        $authService->changePassword($user, 'wrong_password', 'new_password');
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. DEVICE CAP STRICTLY 5
    // ═══════════════════════════════════════════════════════════════

    public function testSettingsRejectsMaxDevicesAbove5(): void
    {
        $settings = $this->createMock(Settings::class);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $service = new \App\Service\SettingsService(
            $settingsRepo,
            $this->createMock(EntityManagerInterface::class),
            $orgScope,
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
        );

        $admin = $this->createMock(User::class);
        $admin->method('getRole')->willReturn(UserRole::ADMINISTRATOR);
        $admin->method('getOrganizationId')->willReturn('org-1');
        $admin->method('getUsername')->willReturn('admin');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_devices_per_user');

        $service->updateSettings($admin, ['max_devices_per_user' => 6]);
    }

    public function testSettingsAcceptsMaxDevices5(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->expects($this->once())->method('setMaxDevicesPerUser')->with(5);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $service = new \App\Service\SettingsService(
            $settingsRepo,
            $this->createMock(EntityManagerInterface::class),
            $orgScope,
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
        );

        $admin = $this->createMock(User::class);
        $admin->method('getRole')->willReturn(UserRole::ADMINISTRATOR);
        $admin->method('getOrganizationId')->willReturn('org-1');
        $admin->method('getUsername')->willReturn('admin');

        $service->updateSettings($admin, ['max_devices_per_user' => 5]);
    }

    public function testSettingsRejectsMaxDevicesZero(): void
    {
        $settings = $this->createMock(Settings::class);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $service = new \App\Service\SettingsService(
            $settingsRepo,
            $this->createMock(EntityManagerInterface::class),
            $orgScope,
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
        );

        $admin = $this->createMock(User::class);
        $admin->method('getRole')->willReturn(UserRole::ADMINISTRATOR);
        $admin->method('getOrganizationId')->willReturn('org-1');
        $admin->method('getUsername')->willReturn('admin');

        $this->expectException(\InvalidArgumentException::class);
        $service->updateSettings($admin, ['max_devices_per_user' => 0]);
    }

    public function testAuthServiceClampsDeviceCapTo5ViaRealService(): void
    {
        // Settings says max_devices=10, but AuthService clamps to min(10,5)=5.
        // Prove this by calling real authenticate() and verifying revokeExcessByUserId
        // receives 5, not 10.
        $deviceSessionRepo = $this->createMock(\App\Repository\DeviceSessionRepository::class);
        $deviceSessionRepo->expects($this->once())
            ->method('revokeExcessByUserId')
            ->with('user-1', 5) // MUST be 5, not 10
            ->willReturn(0);

        $settings = $this->createMock(Settings::class);
        $settings->method('getMaxDevicesPerUser')->willReturn(10); // above hard limit
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $org = $this->createMock(\App\Entity\Organization::class);
        $org->method('getId')->willReturn('org-1');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('isActive')->willReturn(true);
        $user->method('isFrozen')->willReturn(false);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getPasswordHash')->willReturn(password_hash('pass', PASSWORD_BCRYPT));
        $user->method('getRole')->willReturn(UserRole::TENANT);

        $userRepo = $this->createMock(\App\Repository\UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $jwtManager = $this->createMock(\App\Security\JwtTokenManager::class);
        $jwtManager->method('createRefreshToken')->willReturn('rt');
        $jwtManager->method('hashRefreshToken')->willReturn('h');
        $jwtManager->method('getRefreshTokenTtl')->willReturn(86400);
        $jwtManager->method('createAccessToken')->willReturn('at');
        $jwtManager->method('getAccessTokenTtl')->willReturn(900);

        $authService = new \App\Service\AuthService(
            $userRepo, $deviceSessionRepo, $settingsRepo, $jwtManager,
            $this->createMock(AuditService::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $result = $authService->authenticate('user-1', 'pass', 'dev', 'cli');
        $this->assertArrayHasKey('access_token', $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. API ERROR SHAPE — no raw identifiers leaked
    // ═══════════════════════════════════════════════════════════════

    public function testAuthenticationErrorDoesNotLeakInternals(): void
    {
        $e = new AuthenticationException('Invalid credentials');
        $arr = $e->toArray();

        $this->assertArrayHasKey('code', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertSame(401, $arr['code']);
        // Must not contain stack trace, file paths, or session IDs
        $this->assertStringNotContainsString('.php', $arr['message']);
    }

    public function testAccessDeniedErrorShape(): void
    {
        $e = new \App\Exception\AccessDeniedException();
        $arr = $e->toArray();

        $this->assertSame(403, $arr['code']);
        $this->assertArrayHasKey('message', $arr);
    }
}
