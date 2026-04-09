<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditActions;
use App\Entity\DeviceSession;
use App\Entity\Organization;
use App\Entity\Settings;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AccountFrozenException;
use App\Exception\AuthenticationException;
use App\Exception\BootstrapAlreadyCompletedException;
use App\Repository\DeviceSessionRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use App\Security\JwtTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class AuthService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly DeviceSessionRepository $deviceSessionRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly JwtTokenManager $jwtTokenManager,
        private readonly AuditService $auditService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function authenticate(string $username, string $password, string $deviceLabel, string $clientDeviceId): array
    {
        $user = $this->userRepository->findByUsername($username);

        if ($user === null || !password_verify($password, $user->getPasswordHash())) {
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->isFrozen()) {
            throw new AccountFrozenException();
        }

        if (!$user->isActive()) {
            throw new AuthenticationException('Account is inactive');
        }

        $settings = $this->settingsRepository->findByOrganizationId($user->getOrganizationId());
        $maxDevices = min($settings !== null ? $settings->getMaxDevicesPerUser() : 5, 5);

        $refreshToken = $this->jwtTokenManager->createRefreshToken();
        $refreshTokenHash = $this->jwtTokenManager->hashRefreshToken($refreshToken);
        $refreshTokenTtl = $this->jwtTokenManager->getRefreshTokenTtl();
        $expiresAt = new \DateTimeImmutable("+{$refreshTokenTtl} seconds");

        // Atomic: revoke excess sessions + create new session in a single
        // transaction. Prevents a concurrent login from slipping in between
        // the revoke and the insert.
        //
        // ── CONCURRENCY SAFETY ──────────────────────────────────────
        //
        // Transaction flow:
        //   1. BEGIN
        //   2. SELECT id FROM users WHERE id=? FOR UPDATE   → lock user row
        //   3. SELECT * FROM device_sessions … FOR UPDATE   → lock session rows
        //   4. Revoke excess (UPDATE revoked_at)
        //   5. INSERT new session
        //   6. COMMIT
        //
        // WHY SELECT FOR UPDATE ON THE USER ROW GUARANTEES SERIALIZATION:
        //
        //   InnoDB's FOR UPDATE acquires an exclusive row lock that is held
        //   until the transaction commits. A second transaction issuing the
        //   same SELECT FOR UPDATE on the same user row will BLOCK at step 2
        //   until the first transaction releases its lock at step 6 (COMMIT).
        //
        //   After unblocking, the second transaction's session-count query
        //   (step 3) reads COMMITTED state, which includes the first
        //   transaction's INSERT. Therefore the second transaction sees the
        //   accurate count and revokes accordingly.
        //
        // WHY THIS HOLDS EVEN WHEN THE SESSION TABLE HAS 0 ROWS:
        //
        //   The session-row lock (step 3) protects nothing when the result
        //   set is empty — there are no rows to lock. Without the user-row
        //   lock, two concurrent logins could both read count=0, skip
        //   revocation, and both INSERT, reaching count=2 (still safe for
        //   cap=5, but the principle breaks at cap=1).
        //
        //   The user-row lock is the PRIMARY serialization point because the
        //   users row ALWAYS exists for any authenticated user. It serializes
        //   access regardless of how many session rows exist.
        //
        // The session-row PESSIMISTIC_WRITE (step 3) is DEFENSE-IN-DEPTH:
        // it ensures the count is read under lock even if isolation level is
        // relaxed below REPEATABLE READ.
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            // Lock the user row to serialize concurrent logins for this user.
            $conn->executeStatement(
                'SELECT id FROM users WHERE id = ? FOR UPDATE',
                [$user->getId()],
            );

            $this->deviceSessionRepository->revokeExcessByUserId($user->getId(), $maxDevices);

            $session = new DeviceSession(
                Uuid::v4()->toRfc4122(),
                $user,
                $refreshTokenHash,
                $deviceLabel,
                $clientDeviceId,
                $expiresAt,
            );

            $this->em->persist($session);
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        $accessToken = $this->jwtTokenManager->createAccessToken($user);
        $accessTokenTtl = $this->jwtTokenManager->getAccessTokenTtl();

        $this->auditService->log(
            $user->getOrganizationId(),
            $user,
            $user->getUsername(),
            AuditActions::AUTH_LOGIN,
            'DeviceSession',
            $session->getId(),
            null,
            ['device_label' => $deviceLabel],
            $clientDeviceId,
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessTokenTtl,
            'session_id' => $session->getId(),
            'user' => $user,
        ];
    }

    public function refreshToken(string $refreshTokenValue): array
    {
        $hash = $this->jwtTokenManager->hashRefreshToken($refreshTokenValue);
        $session = $this->deviceSessionRepository->findByRefreshTokenHash($hash);

        if ($session === null) {
            throw new AuthenticationException('Invalid refresh token');
        }

        if ($session->isRevoked()) {
            throw new AuthenticationException('Refresh token has been revoked');
        }

        if ($session->isExpired()) {
            throw new AuthenticationException('Refresh token has expired');
        }

        $session->updateLastSeen();
        $this->em->flush();

        $user = $session->getUser();
        $accessToken = $this->jwtTokenManager->createAccessToken($user);
        $accessTokenTtl = $this->jwtTokenManager->getAccessTokenTtl();

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshTokenValue,
            'expires_in' => $accessTokenTtl,
            'user' => $user,
        ];
    }

    public function logout(User $user, string $sessionId): void
    {
        $session = $this->deviceSessionRepository->find($sessionId);

        if ($session === null) {
            throw new \App\Exception\EntityNotFoundException('DeviceSession', $sessionId);
        }

        if ($session->getUserId() !== $user->getId()) {
            throw new \App\Exception\AccessDeniedException('Session does not belong to user');
        }

        $session->revoke();
        $this->em->flush();

        $this->auditService->log(
            $user->getOrganizationId(),
            $user,
            $user->getUsername(),
            AuditActions::AUTH_LOGOUT,
            'DeviceSession',
            $sessionId,
        );
    }

    public function logoutAllSessions(User $user): void
    {
        $this->deviceSessionRepository->revokeAllByUserId($user->getId());

        $this->auditService->log(
            $user->getOrganizationId(),
            $user,
            $user->getUsername(),
            AuditActions::AUTH_LOGOUT,
            'User',
            $user->getId(),
            null,
            ['scope' => 'all_sessions'],
        );
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!password_verify($currentPassword, $user->getPasswordHash())) {
            throw new AuthenticationException('Current password is incorrect');
        }

        $user->setPasswordHash(password_hash($newPassword, PASSWORD_BCRYPT));
        $this->em->flush();

        $this->deviceSessionRepository->revokeAllByUserId($user->getId());

        $this->auditService->log(
            $user->getOrganizationId(),
            $user,
            $user->getUsername(),
            AuditActions::AUTH_PASSWORD_CHANGE,
            'User',
            $user->getId(),
        );
    }

    public function bootstrap(
        string $orgName,
        string $orgCode,
        string $adminUsername,
        string $adminPassword,
        string $adminDisplayName,
        string $defaultCurrency = 'USD',
    ): array {
        // Atomic bootstrap guard: the admin-count check MUST be inside the
        // transaction to prevent a race where two concurrent requests both
        // pass the check. The LOCK IN SHARE MODE on the users table serializes
        // concurrent bootstrap attempts.
        $this->em->beginTransaction();

        try {
            // Lock the users table to serialize concurrent bootstrap attempts.
            // Uses a locking read so any concurrent INSERT will block until
            // this transaction completes.
            $conn = $this->em->getConnection();
            $adminCount = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM users WHERE role = :role FOR UPDATE",
                ['role' => UserRole::ADMINISTRATOR->value],
            );

            if ($adminCount > 0) {
                $this->em->rollback();
                throw new BootstrapAlreadyCompletedException();
            }

            $orgId = Uuid::v4()->toRfc4122();
            $org = new Organization($orgId, $orgCode, $orgName);
            $org->setDefaultCurrency(strtoupper($defaultCurrency));
            $this->em->persist($org);

            $settingsId = Uuid::v4()->toRfc4122();
            $settings = new Settings($settingsId, $org);
            $this->em->persist($settings);

            $userId = Uuid::v4()->toRfc4122();
            $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT);
            $user = new User(
                $userId,
                $org,
                $adminUsername,
                $passwordHash,
                $adminDisplayName,
                UserRole::ADMINISTRATOR,
            );
            $this->em->persist($user);

            $this->em->flush();
            $this->em->commit();
        } catch (BootstrapAlreadyCompletedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            throw $e;
        }

        $this->auditService->log(
            $org->getId(),
            $user,
            $user->getUsername(),
            AuditActions::AUTH_BOOTSTRAP,
            'Organization',
            $org->getId(),
            null,
            ['org_name' => $orgName, 'org_code' => $orgCode, 'admin_username' => $adminUsername],
        );

        return [
            'organization' => $org,
            'user' => $user,
        ];
    }
}
