<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\ThrottleLimitException;
use App\Service\IdempotencyService;
use App\Service\ThrottleService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class ZThrottleAndIdempotencyTest extends KernelTestCase
{
    public function testThrottleServiceRealDbRateLimit(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $throttle = $container->get(ThrottleService::class);
        $this->assertInstanceOf(ThrottleService::class, $throttle);

        $itemId = Uuid::v4()->toRfc4122();

        // Below limit — should succeed
        for ($i = 0; $i < 3; $i++) {
            $throttle->checkAndRecord($itemId, 10);
        }
        $this->addToAssertionCount(1);

        // Prune old attempts — exercises the branch
        $pruned = $throttle->pruneExpiredAttempts(1);
        $this->assertIsInt($pruned);

        // Over limit
        $overLimitItem = Uuid::v4()->toRfc4122();
        $hitLimit = false;
        try {
            for ($i = 0; $i < 5; $i++) {
                $throttle->checkAndRecord($overLimitItem, 2);
            }
        } catch (ThrottleLimitException) {
            $hitLimit = true;
        }
        $this->assertTrue($hitLimit, 'ThrottleLimitException expected when over the per-item limit');
    }

    public function testIdempotencyServiceRealDb(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $idempService = $container->get(IdempotencyService::class);
        $em = $container->get('doctrine.orm.entity_manager');

        // Need a real user
        $org = new Organization(Uuid::v4()->toRfc4122(), 'IDEMP_' . substr(Uuid::v4()->toRfc4122(), 0, 6), 'Idemp Test');
        $em->persist($org);

        $user = new User(
            Uuid::v4()->toRfc4122(), $org,
            'idemp_u_' . substr(Uuid::v4()->toRfc4122(), 0, 6),
            'h', 'Test', UserRole::TENANT,
        );
        $em->persist($user);
        $em->flush();

        $key = 'req-' . Uuid::v4()->toRfc4122();
        $payload = ['result' => 'ok', 'id' => 'abc'];

        // First check — nothing stored yet
        $this->assertNull($idempService->check($user->getId(), $key));

        // Store
        $idempService->store($user, $key, $payload);

        // Second check — returns stored payload
        $result = $idempService->check($user->getId(), $key);
        $this->assertSame($payload, $result);

        // Cleanup expired — returns count
        $cleaned = $idempService->cleanupExpired();
        $this->assertIsInt($cleaned);

        // Cleanup test data
        $conn = $container->get('doctrine.dbal.default_connection');
        try {
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $conn->executeStatement('DELETE FROM idempotency_keys WHERE user_id = ?', [$user->getId()]);
            $conn->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
            $conn->executeStatement('DELETE FROM organizations WHERE id = ?', [$org->getId()]);
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\Throwable) {}
    }
}
