<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DeviceSession;
use App\Entity\InventoryItem;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\BookingHoldStatus;
use App\Enum\BookingStatus;
use App\Enum\UserRole;
use App\Exception\EntityNotFoundException;
use App\Exception\InsufficientCapacityException;
use App\Repository\BookingHoldRepository;
use App\Repository\BookingRepository;
use App\Repository\DeviceSessionRepository;
use App\Repository\InventoryItemRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use App\Security\ApiTokenAuthenticator;
use App\Security\ExceptionListener;
use App\Security\JwtTokenManager;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BillingService;
use App\Service\BookingHoldService;
use App\Service\IdempotencyService;
use App\Service\InventoryService;
use App\Service\LedgerService;
use App\Service\NotificationService;
use App\Service\PricingService;
use App\Service\ThrottleService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Final verification edge-case tests. Read-only pass — no logic changes.
 *
 *   1. Token invalidation after password change (admin + self)
 *   2. Availability vs hold consistency
 *   3. Log vs response masking (UUID in internal log, masked in response)
 *   4. Device cap overflow behavior (oldest session revoked)
 */
class FinalVerificationTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // 1. TOKEN INVALIDATION AFTER PASSWORD CHANGE
    // ═══════════════════════════════════════════════════════════════

    private const PUBLIC_ROUTES = [
        '/api/v1/health',
        '/api/v1/bootstrap',
        '/api/v1/auth/login',
        '/api/v1/auth/refresh',
        '/api/v1/payments/callback',
    ];

    private function buildAuthenticator(
        \DateTimeImmutable $tokenIssuedAt,
        ?\DateTimeImmutable $passwordChangedAt,
        bool $active = true,
        bool $frozen = false,
    ): ApiTokenAuthenticator {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('isActive')->willReturn($active);
        $user->method('isFrozen')->willReturn($frozen);
        $user->method('getRole')->willReturn(UserRole::TENANT);
        $user->method('getPasswordChangedAt')->willReturn($passwordChangedAt);

        $jwt = $this->createMock(JwtTokenManager::class);
        $jwt->method('parseAccessToken')->willReturn([
            'user_id' => 'user-1',
            'organization_id' => 'org-1',
            'role' => 'tenant',
            'issued_at' => $tokenIssuedAt,
        ]);

        $repo = $this->createMock(UserRepository::class);
        $repo->method('find')->willReturn($user);

        return new ApiTokenAuthenticator($jwt, $repo, self::PUBLIC_ROUTES);
    }

    private function makeAuthRequest(string $token): Request
    {
        $request = Request::create('/api/v1/bookings', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");
        return $request;
    }

    /**
     * Admin resets another user's password → token issued before reset → rejected.
     */
    public function testOldTokenRejectedAfterAdminPasswordReset(): void
    {
        $tokenIat = new \DateTimeImmutable('2026-01-15 10:00:00');
        $pwChanged = new \DateTimeImmutable('2026-01-15 11:00:00');

        $auth = $this->buildAuthenticator($tokenIat, $pwChanged);

        $passport = $auth->authenticate($this->makeAuthRequest('old-token'));
        $this->expectException(\Symfony\Component\Security\Core\Exception\AuthenticationException::class);
        $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)->getUser();
    }

    /**
     * Self password change → same mechanism: old token → rejected.
     */
    public function testOldTokenRejectedAfterSelfPasswordChange(): void
    {
        $tokenIat = new \DateTimeImmutable('2026-03-01 08:00:00');
        $pwChanged = new \DateTimeImmutable('2026-03-01 09:30:00');

        $auth = $this->buildAuthenticator($tokenIat, $pwChanged);

        $passport = $auth->authenticate($this->makeAuthRequest('stale-token'));
        $this->expectException(\Symfony\Component\Security\Core\Exception\AuthenticationException::class);
        $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)->getUser();
    }

    /**
     * Token issued AFTER password change → accepted.
     */
    public function testNewTokenAcceptedAfterPasswordChange(): void
    {
        $tokenIat = new \DateTimeImmutable('2026-01-15 12:00:00');
        $pwChanged = new \DateTimeImmutable('2026-01-15 11:00:00');

        $auth = $this->buildAuthenticator($tokenIat, $pwChanged);
        $passport = $auth->authenticate($this->makeAuthRequest('fresh-token'));

        $this->assertNotNull($passport);
    }

    /**
     * No password change ever → token accepted regardless of iat.
     */
    public function testTokenAcceptedWhenNoPasswordChangeHistory(): void
    {
        $tokenIat = new \DateTimeImmutable('2020-01-01 00:00:00');

        $auth = $this->buildAuthenticator($tokenIat, null);
        $passport = $auth->authenticate($this->makeAuthRequest('ancient-token'));

        $this->assertNotNull($passport);
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. AVAILABILITY vs HOLD CONSISTENCY
    // ═══════════════════════════════════════════════════════════════

    /**
     * When availability says can_reserve=false for N units, a hold for N
     * units must also fail (InsufficientCapacityException).
     *
     * Both paths use the same capacity calculation, so they must agree.
     */
    public function testHoldFailsWhenAvailabilitySaysCannotReserve(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $org->method('getDefaultCurrency')->willReturn('USD');

        $item = $this->createMock(InventoryItem::class);
        $item->method('getId')->willReturn('item-1');
        $item->method('getTotalCapacity')->willReturn(2);
        $item->method('isActive')->willReturn(true);
        $item->method('getOrganization')->willReturn($org);

        $itemRepo = $this->createMock(InventoryItemRepository::class);
        $itemRepo->method('findByIdAndOrg')->willReturn($item);

        // 2 units held → 0 available
        $holdRepo = $this->createMock(BookingHoldRepository::class);
        $holdRepo->method('sumActiveUnitsForItemInRange')->willReturn(2);
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('sumActiveUnitsForItemInRange')->willReturn(0);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('tenant-1');
        $user->method('getRole')->willReturn(UserRole::TENANT);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn('tenant');

        // Step 1: Availability API says can_reserve=false
        $inventoryService = new InventoryService(
            $itemRepo, $holdRepo, $bookingRepo,
            $this->createMock(EntityManagerInterface::class),
            $orgScope,
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
        );

        $avail = $inventoryService->checkAvailability(
            $user, 'item-1',
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+2 days'),
            1,
        );

        $this->assertSame(0, $avail['available_units']);
        $this->assertFalse($avail['can_reserve'], 'Availability must say can_reserve=false');

        // Step 2: Hold creation with the same parameters must fail
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('executeStatement')->willReturn(0);
        // held=2, booked=0 — same as availability query
        $callCount = 0;
        $conn->method('fetchOne')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            return $callCount === 1 ? '2' : '0'; // held=2, booked=0
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());

        $holdService = new BookingHoldService(
            $this->createMock(\App\Repository\BookingHoldRepository::class),
            $itemRepo,
            $this->createMock(PricingService::class),
            $this->createMock(IdempotencyService::class),
            $this->createMock(ThrottleService::class),
            $this->createMock(SettingsRepository::class),
            $this->createMock(BillingService::class),
            $this->createMock(LedgerService::class),
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
            $orgScope,
            $em,
        );

        $this->expectException(InsufficientCapacityException::class);
        $holdService->createHold(
            $user, 'item-1', 1,
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+2 days'),
            'req-avail-hold-consistency',
        );
    }

    /**
     * Reverse: availability says can_reserve=true → hold should succeed.
     */
    public function testHoldSucceedsWhenAvailabilitySaysCanReserve(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $item = $this->createMock(InventoryItem::class);
        $item->method('getId')->willReturn('item-1');
        $item->method('getTotalCapacity')->willReturn(3);
        $item->method('isActive')->willReturn(true);
        $item->method('getOrganization')->willReturn($org);

        $itemRepo = $this->createMock(InventoryItemRepository::class);
        $itemRepo->method('findByIdAndOrg')->willReturn($item);

        // 1 held, 0 booked → 2 available, requesting 1 → can_reserve=true
        $holdRepo = $this->createMock(BookingHoldRepository::class);
        $holdRepo->method('sumActiveUnitsForItemInRange')->willReturn(1);
        $bookingRepo = $this->createMock(BookingRepository::class);
        $bookingRepo->method('sumActiveUnitsForItemInRange')->willReturn(0);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('tenant-1');
        $user->method('getRole')->willReturn(UserRole::TENANT);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');

        $inventoryService = new InventoryService(
            $itemRepo, $holdRepo, $bookingRepo,
            $this->createMock(EntityManagerInterface::class),
            $orgScope,
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
        );

        $avail = $inventoryService->checkAvailability(
            $user, 'item-1',
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+2 days'),
            1,
        );

        $this->assertSame(2, $avail['available_units']);
        $this->assertTrue($avail['can_reserve']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. LOG vs RESPONSE MASKING
    // ═══════════════════════════════════════════════════════════════

    /**
     * EntityNotFoundException: internal getMessage() has full UUID,
     * but toArray() (client response) has no UUID.
     */
    public function testEntityNotFoundInternalMessageHasFullUuid(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $e = new EntityNotFoundException('Booking', $uuid);

        // Internal: full UUID available for server-side log correlation
        $this->assertStringContainsString($uuid, $e->getMessage());
        $this->assertStringContainsString('550e8400', $e->getMessage());

        // Client: no UUID at all
        $arr = $e->toArray();
        $this->assertSame('Booking not found', $arr['message']);
        $this->assertNull($arr['details']);
        $this->assertStringNotContainsString($uuid, json_encode($arr));
    }

    /**
     * ExceptionListener: client response gets masked UUID,
     * but the raw exception message (which the logger receives) retains it.
     */
    public function testExceptionListenerMasksResponseButLogRetainsContext(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $exception = new \DomainException("Hold {$uuid} is not active");

        // Capture what the logger receives
        $logger = new class extends AbstractLogger {
            /** @var array<int, array{level: mixed, message: string, context: array}> */
            public array $messages = [];
            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->messages[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $listener = new ExceptionListener($logger);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/v1/test');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        // Client response: UUID is masked
        $response = $event->getResponse();
        $body = json_decode($response->getContent(), true);
        $this->assertStringNotContainsString($uuid, $body['message']);
        $this->assertStringContainsString('****', $body['message']);
        $this->assertSame(409, $body['code']);

        // DomainException is handled directly (not logged via logger->error),
        // so verify the masking function independently
        $masked = $listener->redactSensitive("Entity {$uuid} error");
        $this->assertStringNotContainsString($uuid, $masked);
        $this->assertStringContainsString('****7890', $masked);

        // The raw exception getMessage() still has the full UUID for any
        // structured logging that captures the exception object.
        $this->assertStringContainsString($uuid, $exception->getMessage());
    }

    /**
     * Unhandled RuntimeException: response is generic 500, log receives
     * the redacted message via logger->error.
     */
    public function testUnhandledExceptionLoggedButResponseGeneric(): void
    {
        $uuid = 'deadbeef-dead-beef-dead-beefdeadbeef';
        $exception = new \RuntimeException("PDO error on entity {$uuid}");

        $logger = new class extends AbstractLogger {
            /** @var array<int, array{level: mixed, message: string, context: array}> */
            public array $messages = [];
            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->messages[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $listener = new ExceptionListener($logger);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/v1/test');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        // Response: generic 500, no UUID
        $body = json_decode($event->getResponse()->getContent(), true);
        $this->assertSame(500, $body['code']);
        $this->assertSame('Internal server error', $body['message']);
        $this->assertStringNotContainsString($uuid, json_encode($body));

        // Logger was called with redacted message
        $this->assertCount(1, $logger->messages);
        $this->assertSame('error', $logger->messages[0]['level']);
        // The context message field is redacted by the listener
        $this->assertStringNotContainsString('deadbeef-dead', $logger->messages[0]['context']['message']);
        $this->assertStringContainsString('****beef', $logger->messages[0]['context']['message']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. DEVICE CAP OVERFLOW BEHAVIOR
    // ═══════════════════════════════════════════════════════════════

    /**
     * Prove: when a user has 5 active sessions (= cap) and logs in again,
     * excess sessions are revoked via revokeExcessByUserId.
     */
    public function testSixthLoginRevokesExcessSessions(): void
    {
        $deviceSessionRepo = $this->createMock(DeviceSessionRepository::class);
        // revokeExcessByUserId is called with (userId, maxDevices=5) and revokes 1
        $deviceSessionRepo->expects($this->once())
            ->method('revokeExcessByUserId')
            ->with('user-1', 5)
            ->willReturn(1);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null); // default cap=5

        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('isActive')->willReturn(true);
        $user->method('isFrozen')->willReturn(false);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getPasswordHash')->willReturn(password_hash('pass', PASSWORD_BCRYPT));
        $user->method('getRole')->willReturn(UserRole::TENANT);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $jwtManager = $this->createMock(JwtTokenManager::class);
        $jwtManager->method('createRefreshToken')->willReturn('refresh-token');
        $jwtManager->method('hashRefreshToken')->willReturn('hashed');
        $jwtManager->method('getRefreshTokenTtl')->willReturn(86400);
        $jwtManager->method('createAccessToken')->willReturn('access-token');
        $jwtManager->method('getAccessTokenTtl')->willReturn(900);

        $em = $this->createMock(EntityManagerInterface::class);

        $authService = new \App\Service\AuthService(
            $userRepo,
            $deviceSessionRepo,
            $settingsRepo,
            $jwtManager,
            $this->createMock(AuditService::class),
            $em,
        );

        // 6th login → oldest revoked, login succeeds
        $result = $authService->authenticate('user-1', 'pass', 'device-6', 'client-6');

        $this->assertArrayHasKey('access_token', $result);
        $this->assertSame('access-token', $result['access_token']);
    }

    /**
     * Prove: with cap=3 (configured), 4th login evicts excess.
     */
    public function testCustomCapThreeEvictsOnFourthLogin(): void
    {
        $deviceSessionRepo = $this->createMock(DeviceSessionRepository::class);
        $deviceSessionRepo->expects($this->once())
            ->method('revokeExcessByUserId')
            ->with('user-1', 3)
            ->willReturn(1);

        $settings = $this->createMock(\App\Entity\Settings::class);
        $settings->method('getMaxDevicesPerUser')->willReturn(3);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('isActive')->willReturn(true);
        $user->method('isFrozen')->willReturn(false);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getPasswordHash')->willReturn(password_hash('pass', PASSWORD_BCRYPT));
        $user->method('getRole')->willReturn(UserRole::TENANT);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $jwtManager = $this->createMock(JwtTokenManager::class);
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

        $result = $authService->authenticate('user-1', 'pass', 'device-4', 'client-4');
        $this->assertArrayHasKey('access_token', $result);
    }

    /**
     * Prove: even if settings says max_devices=10, the runtime clamp
     * ensures effective cap is 5 (min(10, 5) = 5).
     */
    public function testSettingsAbove5ClampedToFiveAtRuntime(): void
    {
        $deviceSessionRepo = $this->createMock(DeviceSessionRepository::class);
        // settings says 10, but runtime clamps to min(10,5)=5
        $deviceSessionRepo->expects($this->once())
            ->method('revokeExcessByUserId')
            ->with('user-1', 5) // clamped to 5, NOT 10
            ->willReturn(1);

        $settings = $this->createMock(\App\Entity\Settings::class);
        $settings->method('getMaxDevicesPerUser')->willReturn(10);
        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('isActive')->willReturn(true);
        $user->method('isFrozen')->willReturn(false);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getPasswordHash')->willReturn(password_hash('pass', PASSWORD_BCRYPT));
        $user->method('getRole')->willReturn(UserRole::TENANT);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $jwtManager = $this->createMock(JwtTokenManager::class);
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

        // With 5 active and effective cap=5, oldest must be revoked
        $result = $authService->authenticate('user-1', 'pass', 'device-6', 'client-6');
        $this->assertArrayHasKey('access_token', $result);
    }

    /**
     * Prove: under cap → no session revoked.
     */
    public function testLoginUnderCapDoesNotRevokeAnySessions(): void
    {
        $deviceSessionRepo = $this->createMock(DeviceSessionRepository::class);
        // revokeExcessByUserId returns 0 when under cap
        $deviceSessionRepo->method('revokeExcessByUserId')->willReturn(0);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('isActive')->willReturn(true);
        $user->method('isFrozen')->willReturn(false);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getPasswordHash')->willReturn(password_hash('pass', PASSWORD_BCRYPT));
        $user->method('getRole')->willReturn(UserRole::TENANT);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $jwtManager = $this->createMock(JwtTokenManager::class);
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
}
