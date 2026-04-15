<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\BookingHold;
use App\Entity\DeviceSession;
use App\Entity\InventoryItem;
use App\Entity\LedgerEntry;
use App\Entity\Organization;
use App\Entity\Settings;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\BookingHoldStatus;
use App\Enum\BookingStatus;
use App\Enum\CapacityMode;
use App\Enum\LedgerEntryType;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\AccountFrozenException;
use App\Exception\AuthenticationException;
use App\Exception\EntityNotFoundException;
use App\Repository\BillRepository;
use App\Repository\BookingRepository;
use App\Repository\DeviceSessionRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use App\Security\JwtTokenManager;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\LedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Bulk coverage tests hitting service/repository method happy paths with
 * real entities + mocked infra. Written as a single pass — no per-method
 * feedback loop — to avoid the slow test-run churn.
 */
class BulkServiceCoverageTest extends TestCase
{
    private Organization $org;
    private User $admin;
    private User $tenant;

    protected function setUp(): void
    {
        $this->org = new Organization('org-blk', 'BLK', 'Blk Org', 'USD');
        $this->admin = new User('admin-blk', $this->org, 'admb', 'h', 'A', UserRole::ADMINISTRATOR);
        $this->tenant = new User('ten-blk', $this->org, 'tenb', 'h', 'T', UserRole::TENANT);
    }

    // ═══════════════════════════════════════════════════════════════
    // AuthService
    // ═══════════════════════════════════════════════════════════════

    private function makeAuthService(
        UserRepository $userRepo,
        DeviceSessionRepository $sessionRepo,
        ?SettingsRepository $settingsRepo = null,
        ?EntityManagerInterface $em = null,
        ?JwtTokenManager $jwt = null,
    ): AuthService {
        return new AuthService(
            $userRepo,
            $sessionRepo,
            $settingsRepo ?? $this->createMock(SettingsRepository::class),
            $jwt ?? $this->createMock(JwtTokenManager::class),
            $this->createMock(AuditService::class),
            $em ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    public function testAuthenticateUnknownUserThrows(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn(null);

        $svc = $this->makeAuthService($userRepo, $this->createMock(DeviceSessionRepository::class));
        $this->expectException(AuthenticationException::class);
        $svc->authenticate('nobody', 'x', 'd', 'c');
    }

    public function testAuthenticateBadPasswordThrows(): void
    {
        $user = new User('u-x', $this->org, 'x', password_hash('correctpass', PASSWORD_BCRYPT), 'X', UserRole::TENANT);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $svc = $this->makeAuthService($userRepo, $this->createMock(DeviceSessionRepository::class));
        $this->expectException(AuthenticationException::class);
        $svc->authenticate('x', 'wrongpass', 'd', 'c');
    }

    public function testAuthenticateFrozenAccountThrows(): void
    {
        $user = new User('u-f', $this->org, 'f', password_hash('pw', PASSWORD_BCRYPT), 'F', UserRole::TENANT);
        $user->setIsFrozen(true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $svc = $this->makeAuthService($userRepo, $this->createMock(DeviceSessionRepository::class));
        $this->expectException(AccountFrozenException::class);
        $svc->authenticate('f', 'pw', 'd', 'c');
    }

    public function testAuthenticateInactiveAccountThrows(): void
    {
        $user = new User('u-i', $this->org, 'i', password_hash('pw', PASSWORD_BCRYPT), 'I', UserRole::TENANT);
        $user->setIsActive(false);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $svc = $this->makeAuthService($userRepo, $this->createMock(DeviceSessionRepository::class));
        $this->expectException(AuthenticationException::class);
        $svc->authenticate('i', 'pw', 'd', 'c');
    }

    public function testAuthenticateSuccessCreatesSessionAndReturnsTokens(): void
    {
        $user = new User('u-ok', $this->org, 'ok', password_hash('pw', PASSWORD_BCRYPT), 'OK', UserRole::TENANT);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $sessionRepo = $this->createMock(DeviceSessionRepository::class);
        $sessionRepo->expects($this->once())->method('revokeExcessByUserId');

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settings = $this->createMock(Settings::class);
        $settings->method('getMaxDevicesPerUser')->willReturn(3);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $jwt = $this->createMock(JwtTokenManager::class);
        $jwt->method('createRefreshToken')->willReturn('rt-plain');
        $jwt->method('hashRefreshToken')->willReturn('rt-hash');
        $jwt->method('getRefreshTokenTtl')->willReturn(3600);
        $jwt->method('createAccessToken')->willReturn('at-plain');
        $jwt->method('getAccessTokenTtl')->willReturn(900);

        // Mock connection with beginTransaction/commit + executeStatement
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())->method('beginTransaction');
        $conn->expects($this->once())->method('commit');
        $conn->method('executeStatement')->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->atLeastOnce())->method('flush');

        $svc = $this->makeAuthService($userRepo, $sessionRepo, $settingsRepo, $em, $jwt);
        $result = $svc->authenticate('ok', 'pw', 'dev', 'cdev');

        $this->assertSame('at-plain', $result['access_token']);
        $this->assertSame('rt-plain', $result['refresh_token']);
        $this->assertSame(900, $result['expires_in']);
    }

    public function testAuthenticateWithDefaultSettingsFallback(): void
    {
        $user = new User('u-ds', $this->org, 'ds', password_hash('pw', PASSWORD_BCRYPT), 'DS', UserRole::TENANT);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $sessionRepo = $this->createMock(DeviceSessionRepository::class);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

        $jwt = $this->createMock(JwtTokenManager::class);
        $jwt->method('createRefreshToken')->willReturn('rt');
        $jwt->method('hashRefreshToken')->willReturn('rth');
        $jwt->method('getRefreshTokenTtl')->willReturn(3600);
        $jwt->method('createAccessToken')->willReturn('at');
        $jwt->method('getAccessTokenTtl')->willReturn(900);

        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $svc = $this->makeAuthService($userRepo, $sessionRepo, $settingsRepo, $em, $jwt);
        $result = $svc->authenticate('ds', 'pw', 'd', 'c');
        $this->assertSame('at', $result['access_token']);
    }

    public function testRefreshTokenUnknownThrows(): void
    {
        $sessionRepo = $this->createMock(DeviceSessionRepository::class);
        $sessionRepo->method('findByRefreshTokenHash')->willReturn(null);

        $jwt = $this->createMock(JwtTokenManager::class);
        $jwt->method('hashRefreshToken')->willReturn('h');

        $svc = $this->makeAuthService($this->createMock(UserRepository::class), $sessionRepo, null, null, $jwt);
        $this->expectException(AuthenticationException::class);
        $svc->refreshToken('bogus');
    }

    public function testRefreshTokenRevokedThrows(): void
    {
        $user = new User('u-rv', $this->org, 'rv', 'h', 'RV', UserRole::TENANT);
        $session = new DeviceSession(
            'sess-rv', $user, 'hash', 'label', 'dev',
            new \DateTimeImmutable('+1 day'),
        );
        $session->revoke();

        $sessionRepo = $this->createMock(DeviceSessionRepository::class);
        $sessionRepo->method('findByRefreshTokenHash')->willReturn($session);

        $jwt = $this->createMock(JwtTokenManager::class);
        $jwt->method('hashRefreshToken')->willReturn('h');

        $svc = $this->makeAuthService($this->createMock(UserRepository::class), $sessionRepo, null, null, $jwt);
        $this->expectException(AuthenticationException::class);
        $svc->refreshToken('x');
    }

    public function testRefreshTokenExpiredThrows(): void
    {
        $user = new User('u-ex', $this->org, 'ex', 'h', 'EX', UserRole::TENANT);
        $session = new DeviceSession(
            'sess-ex', $user, 'hash', 'label', 'dev',
            new \DateTimeImmutable('-1 hour'),  // already expired
        );

        $sessionRepo = $this->createMock(DeviceSessionRepository::class);
        $sessionRepo->method('findByRefreshTokenHash')->willReturn($session);

        $jwt = $this->createMock(JwtTokenManager::class);
        $jwt->method('hashRefreshToken')->willReturn('h');

        $svc = $this->makeAuthService($this->createMock(UserRepository::class), $sessionRepo, null, null, $jwt);
        $this->expectException(AuthenticationException::class);
        $svc->refreshToken('x');
    }

    public function testRefreshTokenValidReturnsNewAccessToken(): void
    {
        $user = new User('u-rt', $this->org, 'rt', 'h', 'RT', UserRole::TENANT);
        $session = new DeviceSession(
            'sess-rt', $user, 'hash', 'label', 'dev',
            new \DateTimeImmutable('+1 day'),
        );

        $sessionRepo = $this->createMock(DeviceSessionRepository::class);
        $sessionRepo->method('findByRefreshTokenHash')->willReturn($session);

        $jwt = $this->createMock(JwtTokenManager::class);
        $jwt->method('hashRefreshToken')->willReturn('h');
        $jwt->method('createAccessToken')->willReturn('new-at');
        $jwt->method('getAccessTokenTtl')->willReturn(900);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $svc = $this->makeAuthService($this->createMock(UserRepository::class), $sessionRepo, null, $em, $jwt);
        $r = $svc->refreshToken('rt-plain');
        $this->assertSame('new-at', $r['access_token']);
        $this->assertSame('rt-plain', $r['refresh_token']);
        $this->assertSame(900, $r['expires_in']);
    }

    public function testLogoutUnknownSessionThrows(): void
    {
        $sessionRepo = $this->createMock(DeviceSessionRepository::class);
        $sessionRepo->method('find')->willReturn(null);

        $svc = $this->makeAuthService($this->createMock(UserRepository::class), $sessionRepo);
        $this->expectException(EntityNotFoundException::class);
        $svc->logout($this->admin, 'missing-session');
    }

    public function testLogoutOtherUsersSessionDenied(): void
    {
        $otherUser = new User('u-other', $this->org, 'other', 'h', 'O', UserRole::TENANT);
        $session = new DeviceSession('sess-o', $otherUser, 'h', 'l', 'd', new \DateTimeImmutable('+1 day'));

        $sessionRepo = $this->createMock(DeviceSessionRepository::class);
        $sessionRepo->method('find')->willReturn($session);

        $svc = $this->makeAuthService($this->createMock(UserRepository::class), $sessionRepo);
        $this->expectException(AccessDeniedException::class);
        $svc->logout($this->admin, 'sess-o');
    }

    public function testLogoutOwnSessionRevokesIt(): void
    {
        $session = new DeviceSession('sess-own', $this->admin, 'h', 'l', 'd', new \DateTimeImmutable('+1 day'));
        $sessionRepo = $this->createMock(DeviceSessionRepository::class);
        $sessionRepo->method('find')->willReturn($session);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $svc = $this->makeAuthService($this->createMock(UserRepository::class), $sessionRepo, null, $em);
        $svc->logout($this->admin, 'sess-own');
        $this->assertTrue($session->isRevoked());
    }

    public function testLogoutAllSessionsRevokesAllAndLogs(): void
    {
        $sessionRepo = $this->createMock(DeviceSessionRepository::class);
        $sessionRepo->expects($this->once())->method('revokeAllByUserId')->with($this->admin->getId());

        $svc = $this->makeAuthService($this->createMock(UserRepository::class), $sessionRepo);
        $svc->logoutAllSessions($this->admin);
    }

    public function testChangePasswordWithWrongCurrentThrows(): void
    {
        $user = new User('u-cp', $this->org, 'cp', password_hash('correct', PASSWORD_BCRYPT), 'CP', UserRole::TENANT);

        $svc = $this->makeAuthService($this->createMock(UserRepository::class), $this->createMock(DeviceSessionRepository::class));
        $this->expectException(AuthenticationException::class);
        $svc->changePassword($user, 'wrong', 'newpass1234');
    }

    public function testChangePasswordSuccessRevokesAllSessions(): void
    {
        $user = new User('u-ok', $this->org, 'ok', password_hash('current', PASSWORD_BCRYPT), 'OK', UserRole::TENANT);

        $sessionRepo = $this->createMock(DeviceSessionRepository::class);
        $sessionRepo->expects($this->once())->method('revokeAllByUserId')->with($user->getId());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $svc = $this->makeAuthService($this->createMock(UserRepository::class), $sessionRepo, null, $em);
        $svc->changePassword($user, 'current', 'newstrong12345');
        // Password hash should have changed
        $this->assertTrue(password_verify('newstrong12345', $user->getPasswordHash()));
    }

    // ═══════════════════════════════════════════════════════════════
    // AuthService.bootstrap
    // ═══════════════════════════════════════════════════════════════

    public function testBootstrapAdminAlreadyExistsThrows(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(1); // admin exists
        $conn->method('isTransactionActive')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->expects($this->once())->method('beginTransaction');
        $em->expects($this->once())->method('rollback');

        $svc = $this->makeAuthService(
            $this->createMock(UserRepository::class),
            $this->createMock(DeviceSessionRepository::class),
            null, $em,
        );
        $this->expectException(\App\Exception\BootstrapAlreadyCompletedException::class);
        $svc->bootstrap('Org', 'O', 'admin', 'pw', 'Admin', 'USD');
    }

    public function testBootstrapRollsBackOnInnerError(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(0); // no admin yet
        $conn->method('isTransactionActive')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->expects($this->once())->method('beginTransaction');
        $em->method('persist')->willReturnCallback(function () {
            // On the first persist (Organization) throw to exercise the rollback branch
            throw new \RuntimeException('persist blew up');
        });
        $em->expects($this->once())->method('rollback');

        $svc = $this->makeAuthService(
            $this->createMock(UserRepository::class),
            $this->createMock(DeviceSessionRepository::class),
            null, $em,
        );
        $this->expectException(\RuntimeException::class);
        $svc->bootstrap('Org', 'O', 'admin', 'pw', 'Admin', 'USD');
    }

    public function testBootstrapSuccessPersistsOrgSettingsAdmin(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->expects($this->once())->method('beginTransaction');
        // 3 persists: Organization, Settings, User
        $em->expects($this->exactly(3))->method('persist');
        $em->expects($this->once())->method('flush');
        $em->expects($this->once())->method('commit');

        $svc = $this->makeAuthService(
            $this->createMock(UserRepository::class),
            $this->createMock(DeviceSessionRepository::class),
            null, $em,
        );
        $r = $svc->bootstrap('BootOrg', 'BO', 'bootadmin', 'pw', 'Boot Admin', 'eur');
        $this->assertArrayHasKey('organization', $r);
        $this->assertArrayHasKey('user', $r);
        $this->assertSame('BootOrg', $r['organization']->getName());
        $this->assertSame('EUR', $r['organization']->getDefaultCurrency());
        $this->assertSame('bootadmin', $r['user']->getUsername());
        $this->assertSame(UserRole::ADMINISTRATOR, $r['user']->getRole());
    }

    // ═══════════════════════════════════════════════════════════════
    // LedgerService
    // ═══════════════════════════════════════════════════════════════

    private function makeLedgerService(
        ?LedgerEntryRepository $ledgerRepo = null,
        ?EntityManagerInterface $em = null,
        ?OrganizationScope $orgScope = null,
    ): LedgerService {
        return new LedgerService(
            $ledgerRepo ?? $this->createMock(LedgerEntryRepository::class),
            $em ?? $this->createMock(EntityManagerInterface::class),
            $orgScope ?? $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
        );
    }

    public function testLedgerGetEntriesForBillHappy(): void
    {
        $bill = new Bill('b-l', $this->org, null, $this->tenant, BillType::INITIAL, 'USD', '100.00');
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($bill);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByBillId')->willReturn([
            new LedgerEntry('le-1', $this->org, LedgerEntryType::BILL_ISSUED, '100.00', 'USD', null, $bill),
        ]);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-blk');

        $svc = $this->makeLedgerService($ledgerRepo, $em, $orgScope);
        $entries = $svc->getEntriesForBill($this->admin, 'b-l');
        $this->assertCount(1, $entries);
    }

    public function testLedgerGetEntriesForBookingHappy(): void
    {
        $item = new InventoryItem('it-l', $this->org, 'IT-1', 'X', 'studio', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
        $booking = new Booking(
            'bk-l', $this->org, $item, $this->tenant, null,
            new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 day'),
            1, 'USD', '100.00', '100.00',
        );
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($booking);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByBookingId')->willReturn([]);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-blk');

        $svc = $this->makeLedgerService($ledgerRepo, $em, $orgScope);
        $entries = $svc->getEntriesForBooking($this->admin, 'bk-l');
        $this->assertIsArray($entries);
    }

    public function testLedgerCalculateBillBalanceAllTypes(): void
    {
        $bill = new Bill('b-bal', $this->org, null, $this->tenant, BillType::INITIAL, 'USD', '200.00');
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($bill);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        // 100 bill + 50 penalty - 120 payment + 20 refund = 50
        $ledgerRepo->method('findByBillId')->willReturn([
            new LedgerEntry('l-1', $this->org, LedgerEntryType::BILL_ISSUED, '100.00', 'USD', null, $bill),
            new LedgerEntry('l-2', $this->org, LedgerEntryType::PENALTY_APPLIED, '50.00', 'USD', null, $bill),
            new LedgerEntry('l-3', $this->org, LedgerEntryType::PAYMENT_RECEIVED, '120.00', 'USD', null, $bill),
            new LedgerEntry('l-4', $this->org, LedgerEntryType::REFUND_ISSUED, '20.00', 'USD', null, $bill),
        ]);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-blk');

        $svc = $this->makeLedgerService($ledgerRepo, $em, $orgScope);
        $balance = $svc->calculateBillBalance($this->admin, 'b-bal');
        $this->assertSame('50.00', $balance);
    }

    public function testLedgerCalculateBillBalanceVoidedResetsToZero(): void
    {
        $bill = new Bill('b-void', $this->org, null, $this->tenant, BillType::INITIAL, 'USD', '100.00');
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($bill);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByBillId')->willReturn([
            new LedgerEntry('l-1', $this->org, LedgerEntryType::BILL_ISSUED, '100.00', 'USD', null, $bill),
            new LedgerEntry('l-2', $this->org, LedgerEntryType::BILL_VOIDED, '100.00', 'USD', null, $bill),
        ]);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-blk');

        $svc = $this->makeLedgerService($ledgerRepo, $em, $orgScope);
        $this->assertSame('0.00', $svc->calculateBillBalance($this->admin, 'b-void'));
    }

    public function testLedgerListEntriesFiltersAndMeta(): void
    {
        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('findByOrg')->willReturn([]);
        $ledgerRepo->method('countByOrg')->willReturn(150);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-blk');

        $svc = $this->makeLedgerService($ledgerRepo, null, $orgScope);
        // Per_page=500 should cap to 100; has_next should be true (150 total)
        $r = $svc->listEntries($this->admin, [], 1, 500);
        $this->assertSame(100, $r['meta']['per_page']);
        $this->assertSame(150, $r['meta']['total']);
        $this->assertTrue($r['meta']['has_next']);
    }
}
