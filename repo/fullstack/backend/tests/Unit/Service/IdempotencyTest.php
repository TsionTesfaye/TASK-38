<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\IdempotencyKey;
use App\Entity\User;
use App\Repository\IdempotencyKeyRepository;
use App\Service\IdempotencyService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IdempotencyTest extends TestCase
{
    private IdempotencyKeyRepository&MockObject $repo;
    private EntityManagerInterface&MockObject $em;
    private IdempotencyService $service;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(IdempotencyKeyRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->service = new IdempotencyService($this->repo, $this->em);
    }

    public function testCheckReturnsNullForNewKey(): void
    {
        $this->repo->method('findByUserAndKey')->willReturn(null);

        $result = $this->service->check('user-1', 'new-key');
        $this->assertNull($result);
    }

    public function testCheckReturnsCachedResponseForExistingKey(): void
    {
        $cached = ['booking_id' => 'booking-123'];

        /** @var IdempotencyKey&MockObject $key */
        $key = $this->createMock(IdempotencyKey::class);
        $key->method('isExpired')->willReturn(false);
        $key->method('getResponsePayloadJson')->willReturn($cached);

        $this->repo->method('findByUserAndKey')->willReturn($key);

        $result = $this->service->check('user-1', 'existing-key');
        $this->assertSame($cached, $result);
    }

    public function testCheckReturnsNullForExpiredKey(): void
    {
        /** @var IdempotencyKey&MockObject $key */
        $key = $this->createMock(IdempotencyKey::class);
        $key->method('isExpired')->willReturn(true);

        $this->repo->method('findByUserAndKey')->willReturn($key);

        $result = $this->service->check('user-1', 'expired-key');
        $this->assertNull($result);
    }

    public function testStoreCreatesNewKey(): void
    {
        /** @var User&MockObject $user */
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->service->store($user, 'new-key', ['booking_id' => 'b-1']);
    }

    public function testCleanupDelegatestoRepository(): void
    {
        $this->repo->expects($this->once())
            ->method('deleteExpired')
            ->willReturn(5);

        $count = $this->service->cleanupExpired();
        $this->assertSame(5, $count);
    }

    public function testDuplicateRequestReturnsSamePayload(): void
    {
        // Simulate the booking hold flow: first call stores, second returns cached
        $payload = ['hold_id' => 'hold-abc'];

        /** @var IdempotencyKey&MockObject $key */
        $key = $this->createMock(IdempotencyKey::class);
        $key->method('isExpired')->willReturn(false);
        $key->method('getResponsePayloadJson')->willReturn($payload);

        // First call: nothing cached
        $this->repo->expects($this->exactly(2))
            ->method('findByUserAndKey')
            ->with('user-1', 'req-key-1')
            ->willReturnOnConsecutiveCalls(null, $key);

        $first = $this->service->check('user-1', 'req-key-1');
        $this->assertNull($first);

        $second = $this->service->check('user-1', 'req-key-1');
        $this->assertSame($payload, $second);
    }
}
