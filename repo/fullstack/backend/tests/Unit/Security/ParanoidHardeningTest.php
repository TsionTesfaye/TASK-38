<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Exception\EntityNotFoundException;
use App\Exception\OrganizationScopeMismatchException;
use App\Repository\UserRepository;
use App\Security\ApiTokenAuthenticator;
use App\Security\ExceptionListener;
use App\Security\JwtTokenManager;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\BackupService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * PARANOID HARDENING TESTS — Zero-trust, adversarial, fail-closed.
 *
 *   Section A: Cross-tenant isolation (every entity type)
 *   Section B: Sensitive data leak prevention
 *   Section C: Crypto safety (nonce, tag, key)
 *   Section D: Concurrent session cap simulation
 *   Section E: End-to-end fail-safe (invalid/unauthorized/tampered/replay/rollback)
 *   Section F: Route coverage — deny-by-default enforcer
 *   Section G: RBAC completeness — every service method enforces
 */
class ParanoidHardeningTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════

    private function makeUser(UserRole $role, string $orgId = 'org-1', string $userId = 'user-1'): User&MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn($orgId);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($userId);
        $user->method('getRole')->willReturn($role);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn($orgId);
        $user->method('getUsername')->willReturn('testuser');
        return $user;
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION A: CROSS-TENANT ISOLATION
    // ═══════════════════════════════════════════════════════════════

    public function testOrganizationScopeDerivesOrgFromUserOnly(): void
    {
        $scope = new OrganizationScope();
        $user = $this->makeUser(UserRole::ADMINISTRATOR, 'org-1');

        // The org ID comes from the user entity, not from any request parameter
        $this->assertSame('org-1', $scope->getOrganizationId($user));
    }

    public function testOrganizationScopeRejectsCrossOrgAccess(): void
    {
        $scope = new OrganizationScope();
        $user = $this->makeUser(UserRole::ADMINISTRATOR, 'org-1');

        // User from org-1 accessing entity from org-2 → MUST fail
        $this->expectException(OrganizationScopeMismatchException::class);
        $scope->assertSameOrganization($user, 'org-2');
    }

    public function testOrganizationScopeAcceptsSameOrg(): void
    {
        $scope = new OrganizationScope();
        $user = $this->makeUser(UserRole::ADMINISTRATOR, 'org-1');

        // Same org → no exception
        $scope->assertSameOrganization($user, 'org-1');
        $this->addToAssertionCount(1);
    }

    /**
     * CRITICAL: Prove that a user from org-1 cannot access backups from org-2.
     */
    public function testCrossTenantBackupAccessBlocked(): void
    {
        // Create backup as org-1
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['bills']);
        $conn = $this->createMock(Connection::class);
        $conn->method('createSchemaManager')->willReturn($schema);
        $conn->method('quoteIdentifier')->willReturnArgument(0);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $tmpDir = sys_get_temp_dir() . '/paranoid_backup_' . uniqid();
        mkdir($tmpDir, 0750, true);

        try {
            $scope1 = $this->createMock(OrganizationScope::class);
            $scope1->method('getOrganizationId')->willReturn('org-1');
            $key = 'test-key-32-chars-long-for-aes!!';
            $service1 = new BackupService($em, $scope1, new RbacEnforcer(), $this->createMock(AuditService::class), $tmpDir, $key);

            $admin1 = $this->makeUser(UserRole::ADMINISTRATOR, 'org-1');
            $backup = $service1->createBackup($admin1);

            // Now try to preview as org-2 → MUST fail
            $scope2 = $this->createMock(OrganizationScope::class);
            $scope2->method('getOrganizationId')->willReturn('org-2');
            $service2 = new BackupService($em, $scope2, new RbacEnforcer(), $this->createMock(AuditService::class), $tmpDir, $key);
            $admin2 = $this->makeUser(UserRole::ADMINISTRATOR, 'org-2');

            $this->expectException(AccessDeniedException::class);
            $service2->previewRestore($admin2, $backup['filename']);
        } finally {
            foreach (glob($tmpDir . '/*') as $f) { @unlink($f); }
            @rmdir($tmpDir);
        }
    }

    /**
     * CRITICAL: Prove tenant cannot access another tenant's resource via RBAC.
     */
    public function testTenantCannotAccessOtherTenantResource(): void
    {
        $rbac = new RbacEnforcer();
        $tenant = $this->makeUser(UserRole::TENANT, 'org-1', 'tenant-A');

        $this->expectException(AccessDeniedException::class);
        $rbac->enforce($tenant, RbacEnforcer::ACTION_VIEW_OWN, 'tenant-B');
    }

    /**
     * CRITICAL: Admin from org-1 cannot elevate to system-wide access.
     */
    public function testAdminScopedToOwnOrg(): void
    {
        $scope = new OrganizationScope();
        $admin = $this->makeUser(UserRole::ADMINISTRATOR, 'org-1');

        // Org scope ALWAYS returns the user's own org, regardless of role
        $this->assertSame('org-1', $scope->getOrganizationId($admin));

        // Cannot access org-2 data
        $this->expectException(OrganizationScopeMismatchException::class);
        $scope->assertSameOrganization($admin, 'org-2');
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION B: SENSITIVE DATA LEAK PREVENTION
    // ═══════════════════════════════════════════════════════════════

    public function testUnhandledExceptionReturnsGeneric500(): void
    {
        $logger = new class extends AbstractLogger {
            public array $messages = [];
            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->messages[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $listener = new ExceptionListener($logger);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/v1/bookings');

        // Simulate a raw PDO exception with full UUID
        $uuid = 'deadbeef-dead-beef-dead-beefdeadbeef';
        $exception = new \RuntimeException("SQLSTATE[23000]: Duplicate key {$uuid} in users.email");
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $body = json_decode($event->getResponse()->getContent(), true);

        // Client MUST NOT see:
        $this->assertSame(500, $body['code']);
        $this->assertSame('Internal server error', $body['message']);
        $this->assertStringNotContainsString($uuid, json_encode($body));
        $this->assertStringNotContainsString('SQLSTATE', json_encode($body));
        $this->assertStringNotContainsString('Duplicate key', json_encode($body));
        $this->assertNull($body['details']);
    }

    public function testAppExceptionMasksUuidsInResponse(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $exception = new EntityNotFoundException('Booking', $uuid);

        $arr = $exception->toArray();

        // toArray MUST NOT contain the full UUID
        $serialized = json_encode($arr);
        $this->assertStringNotContainsString($uuid, $serialized);
        $this->assertSame('Booking not found', $arr['message']);
    }

    public function testDomainExceptionMasksUuidsInResponse(): void
    {
        $logger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };

        $listener = new ExceptionListener($logger);
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $exception = new \DomainException("Hold {$uuid} is not active");
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/api/v1/test'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        $listener->onKernelException($event);
        $body = json_decode($event->getResponse()->getContent(), true);

        $this->assertSame(409, $body['code']);
        $this->assertStringNotContainsString($uuid, $body['message']);
        $this->assertStringContainsString('****', $body['message']);
    }

    public function testUserEntityNeverExposesPasswordHash(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $user = new User('u1', $org, 'testuser', '$2y$10$hashedpassword', 'Test', UserRole::TENANT);

        $json = $user->jsonSerialize();

        // Password hash MUST NOT appear in serialized output
        $serialized = json_encode($json);
        $this->assertStringNotContainsString('$2y$10$', $serialized);
        $this->assertStringNotContainsString('hashedpassword', $serialized);
        $this->assertArrayNotHasKey('password', $json);
        $this->assertArrayNotHasKey('password_hash', $json);
        $this->assertArrayNotHasKey('passwordHash', $json);
    }

    public function testDeviceSessionNeverExposesRefreshTokenHash(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $user = new User('u1', $org, 'test', 'hash', 'Test', UserRole::TENANT);
        $session = new \App\Entity\DeviceSession('s1', $user, 'secret_hash_value', 'dev', 'cli', new \DateTimeImmutable('+1 day'));

        $json = $session->jsonSerialize();
        $serialized = json_encode($json);

        $this->assertStringNotContainsString('secret_hash_value', $serialized);
        $this->assertArrayNotHasKey('refresh_token_hash', $json);
        $this->assertArrayNotHasKey('refreshTokenHash', $json);
    }

    /**
     * Verify no response path returns a stack trace.
     */
    public function testNoStackTraceInAnyErrorResponse(): void
    {
        $logger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };
        $listener = new ExceptionListener($logger);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $exceptions = [
            new \RuntimeException('Runtime failure at /var/www/app/src/Service/Foo.php:42'),
            new \LogicException('Logic error'),
            new \TypeError('Argument #1 must be of type string'),
            new \DomainException('Business rule violated'),
            new \InvalidArgumentException('Bad input'),
            new AccessDeniedException(),
            new AuthenticationException('Invalid credentials'),
            new EntityNotFoundException('Booking', 'some-id'),
        ];

        foreach ($exceptions as $exception) {
            $event = new ExceptionEvent($kernel, Request::create('/api/v1/test'), HttpKernelInterface::MAIN_REQUEST, $exception);
            $listener->onKernelException($event);
            $body = $event->getResponse()->getContent();

            $this->assertStringNotContainsString('.php', $body, 'File path leaked for ' . get_class($exception));
            $this->assertStringNotContainsString('Stack trace', $body, 'Stack trace leaked for ' . get_class($exception));
            $this->assertStringNotContainsString('#0 ', $body, 'Trace frame leaked for ' . get_class($exception));
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION C: CRYPTO SAFETY
    // ═══════════════════════════════════════════════════════════════

    /**
     * Prove: nonces are NEVER reused across encryptions.
     * (Statistical test — 100 encryptions, all nonces unique.)
     */
    public function testNonceNeverReusedAcross100Encryptions(): void
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['bills']);
        $conn = $this->createMock(Connection::class);
        $conn->method('createSchemaManager')->willReturn($schema);
        $conn->method('quoteIdentifier')->willReturnArgument(0);
        $conn->method('fetchAllAssociative')->willReturn([['id' => 'b1', 'organization_id' => 'org-1']]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $tmpDir = sys_get_temp_dir() . '/paranoid_nonce_' . uniqid();
        mkdir($tmpDir, 0750, true);

        $scope = $this->createMock(OrganizationScope::class);
        $scope->method('getOrganizationId')->willReturn('org-1');
        $key = 'nonce-test-key-32-chars-long-pad!';
        $service = new BackupService($em, $scope, new RbacEnforcer(), $this->createMock(AuditService::class), $tmpDir, $key);

        $nonces = [];
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);

        try {
            for ($i = 0; $i < 100; $i++) {
                $backup = $service->createBackup($admin);
                $filepath = $tmpDir . '/' . $backup['filename'];
                $raw = base64_decode(file_get_contents($filepath), true);
                $nonce = substr($raw, 0, 12); // GCM nonce = first 12 bytes
                $nonceHex = bin2hex($nonce);

                $this->assertArrayNotHasKey($nonceHex, $nonces, "Nonce reuse detected on iteration {$i}!");
                $nonces[$nonceHex] = true;

                @unlink($filepath);
            }
        } finally {
            foreach (glob($tmpDir . '/*') as $f) { @unlink($f); }
            @rmdir($tmpDir);
        }

        $this->assertCount(100, $nonces, 'Must have 100 unique nonces');
    }

    public function testGcmTagAlwaysValidated(): void
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['bills']);
        $conn = $this->createMock(Connection::class);
        $conn->method('createSchemaManager')->willReturn($schema);
        $conn->method('quoteIdentifier')->willReturnArgument(0);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $tmpDir = sys_get_temp_dir() . '/paranoid_tag_' . uniqid();
        mkdir($tmpDir, 0750, true);

        $scope = $this->createMock(OrganizationScope::class);
        $scope->method('getOrganizationId')->willReturn('org-1');
        $key = 'tag-test-key-32-chars-long-pad!!';
        $service = new BackupService($em, $scope, new RbacEnforcer(), $this->createMock(AuditService::class), $tmpDir, $key);
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);

        try {
            $backup = $service->createBackup($admin);
            $filepath = $tmpDir . '/' . $backup['filename'];

            // Tamper with every byte of the tag region (bytes 12-27)
            for ($i = 12; $i < 28; $i++) {
                $raw = base64_decode(file_get_contents($filepath), true);
                $raw[$i] = chr(ord($raw[$i]) ^ 0xFF);
                $tampered = $tmpDir . '/' . $backup['filename'];
                file_put_contents($tampered, base64_encode($raw));

                try {
                    $service->previewRestore($admin, $backup['filename']);
                    $this->fail("Tag tamper at byte {$i} must fail decryption");
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('integrity', $e->getMessage());
                }

                // Restore original
                $raw[$i] = chr(ord($raw[$i]) ^ 0xFF);
                file_put_contents($tampered, base64_encode($raw));
            }
        } finally {
            foreach (glob($tmpDir . '/*') as $f) { @unlink($f); }
            @rmdir($tmpDir);
        }
    }

    public function testEmptyKeyRejectedAtStartup(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_ENCRYPTION_KEY');
        new BackupService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(OrganizationScope::class),
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
            '/tmp',
            '',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION D: CONCURRENT SESSION CAP
    // ═══════════════════════════════════════════════════════════════

    /**
     * Simulate 10 concurrent logins using an in-memory session store.
     * Each login invokes the real AuthService logic. After ALL logins,
     * the active session count MUST NOT exceed 5.
     */
    public function testConcurrentLoginSimulationMaxFiveSessions(): void
    {
        $activeSessions = [];
        $maxObserved = 0;

        $deviceSessionRepo = $this->createMock(\App\Repository\DeviceSessionRepository::class);
        $deviceSessionRepo->method('revokeExcessByUserId')
            ->willReturnCallback(function (string $userId, int $maxAllowed) use (&$activeSessions): int {
                $excess = count($activeSessions) - $maxAllowed + 1;
                if ($excess <= 0) return 0;
                array_splice($activeSessions, 0, $excess);
                return $excess;
            });

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
        $user->method('getUsername')->willReturn('testuser');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByUsername')->willReturn($user);

        $settingsRepo = $this->createMock(\App\Repository\SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn(null);

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

        for ($i = 0; $i < 10; $i++) {
            $result = $authService->authenticate('testuser', 'pass', "dev-{$i}", "cli-{$i}");
            $activeSessions[] = $result['session_id'];
            $maxObserved = max($maxObserved, count($activeSessions));
        }

        $this->assertLessThanOrEqual(5, $maxObserved, 'Max active sessions must never exceed 5');
        $this->assertCount(5, $activeSessions, 'After 10 logins, exactly 5 sessions must remain');
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION E: END-TO-END FAIL-SAFE
    // ═══════════════════════════════════════════════════════════════

    /**
     * Invalid input → rejected (not silently accepted).
     */
    public function testInvalidInputRejected(): void
    {
        // Path traversal in backup filename
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn([]);
        $conn = $this->createMock(Connection::class);
        $conn->method('createSchemaManager')->willReturn($schema);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $scope = $this->createMock(OrganizationScope::class);
        $scope->method('getOrganizationId')->willReturn('org-1');
        $service = new BackupService($em, $scope, new RbacEnforcer(), $this->createMock(AuditService::class), '/tmp', 'key-32-chars-long-for-aes-256!!');
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);

        // Path traversal
        $this->expectException(\InvalidArgumentException::class);
        $service->previewRestore($admin, '../../../etc/passwd');
    }

    /**
     * Unauthorized → rejected (not degraded).
     */
    public function testUnauthorizedRejected(): void
    {
        $rbac = new RbacEnforcer();
        $tenant = $this->makeUser(UserRole::TENANT);

        $deniedActions = [
            RbacEnforcer::ACTION_MANAGE_INVENTORY,
            RbacEnforcer::ACTION_MANAGE_BACKUPS,
            RbacEnforcer::ACTION_VIEW_FINANCE,
            RbacEnforcer::ACTION_MANAGE_USERS,
            RbacEnforcer::ACTION_MANAGE_SETTINGS,
            RbacEnforcer::ACTION_VIEW_AUDIT,
            RbacEnforcer::ACTION_MARK_NOSHOW,
            RbacEnforcer::ACTION_CHECK_IN,
        ];

        foreach ($deniedActions as $action) {
            try {
                $rbac->enforce($tenant, $action);
                $this->fail("Tenant must not have {$action} permission");
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Tampered crypto payload → rejected.
     */
    public function testTamperedPayloadRejected(): void
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('listTableNames')->willReturn(['bills']);
        $conn = $this->createMock(Connection::class);
        $conn->method('createSchemaManager')->willReturn($schema);
        $conn->method('quoteIdentifier')->willReturnArgument(0);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $tmpDir = sys_get_temp_dir() . '/paranoid_tamper_' . uniqid();
        mkdir($tmpDir, 0750, true);
        $scope = $this->createMock(OrganizationScope::class);
        $scope->method('getOrganizationId')->willReturn('org-1');
        $key = 'tamper-test-key-32-chars-long-p!';
        $service = new BackupService($em, $scope, new RbacEnforcer(), $this->createMock(AuditService::class), $tmpDir, $key);
        $admin = $this->makeUser(UserRole::ADMINISTRATOR);

        try {
            $backup = $service->createBackup($admin);
            $filepath = $tmpDir . '/' . $backup['filename'];

            // Flip random byte in ciphertext
            $raw = base64_decode(file_get_contents($filepath), true);
            $raw[40] = chr(ord($raw[40]) ^ 0xFF);
            file_put_contents($filepath, base64_encode($raw));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('integrity');
            $service->previewRestore($admin, $backup['filename']);
        } finally {
            foreach (glob($tmpDir . '/*') as $f) { @unlink($f); }
            @rmdir($tmpDir);
        }
    }

    /**
     * Replay attack on idempotent payment callback → safe.
     */
    public function testReplayPaymentCallbackIsSafe(): void
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $bill = $this->createMock(\App\Entity\Bill::class);
        $bill->method('getId')->willReturn('bill-1');

        $payment = new \App\Entity\Payment('pay-replay', $org, $bill, 'req-replay', 'USD', '100.00');
        $payment->transitionTo(\App\Enum\PaymentStatus::SUCCEEDED);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['id' => 'pay-replay', 'status' => 'succeeded']);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $paymentRepo = $this->createMock(\App\Repository\PaymentRepository::class);
        $paymentRepo->method('findByRequestId')->willReturn($payment);

        $ledger = $this->createMock(\App\Service\LedgerService::class);
        $ledger->expects($this->never())->method('createEntry');

        $service = new \App\Service\PaymentService(
            $paymentRepo, $this->createMock(\App\Repository\BillRepository::class),
            $this->createMock(\App\Repository\SettingsRepository::class),
            $ledger, $this->createMock(\App\Service\BillingService::class),
            $this->createMock(\App\Security\PaymentSignatureVerifier::class),
            $this->createMock(OrganizationScope::class),
            $this->createMock(RbacEnforcer::class),
            $this->createMock(AuditService::class),
            $this->createMock(\App\Service\NotificationService::class),
            $em,
        );

        // Call 3 times — must be idempotent
        for ($i = 0; $i < 3; $i++) {
            $result = $service->processCallback('req-replay', 'sig', [
                'status' => 'succeeded', 'amount' => '100.00', 'currency' => 'USD',
            ]);
            $this->assertSame(\App\Enum\PaymentStatus::SUCCEEDED, $result->getStatus());
        }
    }

    /**
     * Frozen user → authentication rejected at token validation.
     */
    public function testFrozenUserRejectedAtAuthenticator(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('frozen-1');
        $user->method('isActive')->willReturn(true);
        $user->method('isFrozen')->willReturn(true);
        $user->method('getRole')->willReturn(UserRole::TENANT);

        $jwt = $this->createMock(JwtTokenManager::class);
        $jwt->method('parseAccessToken')->willReturn([
            'user_id' => 'frozen-1', 'organization_id' => 'org-1',
            'role' => 'tenant', 'issued_at' => new \DateTimeImmutable(),
        ]);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('find')->willReturn($user);

        $auth = new ApiTokenAuthenticator($jwt, $userRepo, []);
        $request = Request::create('/api/v1/bookings');
        $request->headers->set('Authorization', 'Bearer valid.jwt');

        $this->expectException(\Symfony\Component\Security\Core\Exception\AuthenticationException::class);
        $this->expectExceptionMessage('frozen');
        $auth->authenticate($request);
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION F: ROUTE COVERAGE — DENY-BY-DEFAULT
    // ═══════════════════════════════════════════════════════════════

    /**
     * Prove: the security.yaml catch-all ensures NO /api/ route is
     * ever accidentally public, even if new routes are added.
     */
    public function testSecurityYamlHasCatchAllDenyRule(): void
    {
        $securityYaml = __DIR__ . '/../../../config/packages/security.yaml';
        if (!file_exists($securityYaml)) {
            $this->markTestSkipped('security.yaml not found');
        }

        $config = Yaml::parseFile($securityYaml);
        $rules = $config['security']['access_control'] ?? [];

        // Last rule must be the catch-all
        $lastRule = end($rules);
        $this->assertNotFalse($lastRule);
        $this->assertMatchesRegularExpression('#\^/api/#', $lastRule['path']);
        $this->assertContains('ROLE_USER', (array) ($lastRule['roles'] ?? []));

        // Count public rules
        $publicCount = 0;
        foreach ($rules as $rule) {
            if (isset($rule['allow_if']) && $rule['allow_if'] === 'true') {
                $publicCount++;
            }
        }

        // Exactly 5 public routes
        $this->assertSame(5, $publicCount, 'Exactly 5 public routes must be configured');
    }

    /**
     * Prove: the authenticator's supports() returns true for ALL unknown /api/ paths.
     * A new route added without explicit public marking WILL require auth.
     */
    public function testNewRouteRequiresAuthByDefault(): void
    {
        $auth = new ApiTokenAuthenticator(
            $this->createMock(JwtTokenManager::class),
            $this->createMock(UserRepository::class),
            ['/api/v1/health', '/api/v1/bootstrap', '/api/v1/auth/login',
             '/api/v1/auth/refresh', '/api/v1/payments/callback'],
        );

        // Hypothetical new route → must require auth
        $this->assertTrue($auth->supports(Request::create('/api/v1/new-feature')));
        $this->assertTrue($auth->supports(Request::create('/api/v1/admin/dangerous')));
        $this->assertTrue($auth->supports(Request::create('/api/v1/anything/at/all')));
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION G: RBAC COMPLETENESS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Prove: every action in RbacEnforcer has at least one role granted
     * and at least one role denied — no action is universally open.
     */
    public function testEveryRbacActionHasAtLeastOneDenial(): void
    {
        $allActions = (new \ReflectionClass(RbacEnforcer::class))->getConstants();
        $actionConstants = array_filter($allActions, fn($k) => str_starts_with($k, 'ACTION_'), ARRAY_FILTER_USE_KEY);

        foreach ($actionConstants as $name => $action) {
            $grantedCount = 0;
            $deniedCount = 0;

            foreach (UserRole::cases() as $role) {
                $perms = RbacEnforcer::ROLE_PERMISSIONS[$role->value] ?? [];
                if (in_array($action, $perms, true)) {
                    $grantedCount++;
                } else {
                    $deniedCount++;
                }
            }

            $this->assertGreaterThan(0, $grantedCount, "{$name} has no grants — dead action");
            $this->assertGreaterThan(0, $deniedCount, "{$name} is granted to all roles — no access control");
        }
    }
}
