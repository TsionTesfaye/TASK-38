<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\DTO\Response\AuditLogResponse;
use App\DTO\Response\PlaylistResponse;
use App\DTO\Response\ReconciliationRunResponse;
use App\DTO\Response\TerminalResponse;
use App\DTO\Response\TransferResponse;
use App\Entity\AuditLog;
use App\Entity\Booking;
use App\Entity\BookingEvent;
use App\Entity\InventoryItem;
use App\Entity\Organization;
use App\Entity\ReconciliationRun;
use App\Entity\Terminal;
use App\Entity\TerminalPackageTransfer;
use App\Entity\TerminalPlaylist;
use App\Entity\User;
use App\Enum\BookingEventType;
use App\Enum\BookingStatus;
use App\Enum\CapacityMode;
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

class RemainingEntitiesCoverageTest extends TestCase
{
    private function makeOrgAndUser(): array
    {
        $org = new Organization('org-1', 'O', 'N');
        $user = new User('u-1', $org, 'u', 'h', 'd', UserRole::TENANT);
        return [$org, $user];
    }

    public function testAuditLogEntity(): void
    {
        [$org, $user] = $this->makeOrgAndUser();
        $log = new AuditLog(
            'log-1', $org, $user, 'testuser', 'ACTION_X', 'Booking', 'booking-123',
            ['before' => 1], ['after' => 2], 'client-1',
        );

        $this->assertSame('log-1', $log->getId());
        $this->assertSame($org, $log->getOrganization());
        $this->assertSame($user, $log->getActorUser());
        $this->assertSame('testuser', $log->getActorUsernameSnapshot());
        $this->assertSame('ACTION_X', $log->getActionCode());
        $this->assertSame('Booking', $log->getObjectType());
        $this->assertSame('booking-123', $log->getObjectId());
        $this->assertSame(['before' => 1], $log->getBeforeJson());
        $this->assertSame(['after' => 2], $log->getAfterJson());
        $this->assertSame('client-1', $log->getClientDeviceId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $log->getCreatedAt());

        // jsonSerialize masks the object_id
        $json = $log->jsonSerialize();
        $this->assertStringNotContainsString('booking-123', json_encode($json));
        $this->assertStringContainsString('****', $json['object_id']);
        // But the full ID is still accessible via getter
        $this->assertSame('booking-123', $log->getObjectId());

        // DTO
        $dto = AuditLogResponse::fromEntity($log);
        $this->assertSame('log-1', $dto->id);
    }

    public function testBookingEventEntity(): void
    {
        [$org, $user] = $this->makeOrgAndUser();
        $item = new InventoryItem('i-1', $org, 'A', 'N', 't', 'L', CapacityMode::DISCRETE_UNITS, 1, 'UTC');
        $booking = new Booking('b-1', $org, $item, $user, null,
            new \DateTimeImmutable('+1d'), new \DateTimeImmutable('+2d'),
            1, 'USD', '100.00', '100.00');

        $event = new BookingEvent(
            'be-1', $booking, $user, BookingEventType::ACTIVATED,
            BookingStatus::CONFIRMED, BookingStatus::ACTIVE,
            ['reason' => 'on time'],
        );

        $this->assertSame('be-1', $event->getId());
        $this->assertSame($booking, $event->getBooking());
        $this->assertSame($user, $event->getActorUser());
        $this->assertSame(BookingEventType::ACTIVATED, $event->getEventType());
        $this->assertSame(BookingStatus::CONFIRMED, $event->getBeforeStatus());
        $this->assertSame(BookingStatus::ACTIVE, $event->getAfterStatus());
        $this->assertSame(['reason' => 'on time'], $event->getDetailsJson());
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->getCreatedAt());
    }

    public function testTerminalEntity(): void
    {
        [$org] = $this->makeOrgAndUser();
        $term = new Terminal('t-1', $org, 'KIOSK1', 'Kiosk One', 'Lobby', 'en', false);

        $this->assertSame('t-1', $term->getId());
        $this->assertSame('KIOSK1', $term->getTerminalCode());
        $this->assertSame('Kiosk One', $term->getDisplayName());
        $this->assertSame('Lobby', $term->getLocationGroup());
        $this->assertSame('en', $term->getLanguageCode());
        $this->assertFalse($term->getAccessibilityMode());
        $this->assertTrue($term->isActive());

        if (method_exists($term, 'setDisplayName')) {
            $term->setDisplayName('Updated');
            $this->assertSame('Updated', $term->getDisplayName());
        }
        if (method_exists($term, 'setIsActive')) {
            $term->setIsActive(false);
            $this->assertFalse($term->isActive());
        }

        $json = $term->jsonSerialize();
        $this->assertSame('t-1', $json['id']);

        $dto = TerminalResponse::fromEntity($term);
        $this->assertSame('t-1', $dto->id);
    }

    public function testTerminalPlaylistEntity(): void
    {
        [$org] = $this->makeOrgAndUser();
        $p = new TerminalPlaylist('p-1', $org, 'Welcome', 'Lobby', '0 9 * * *');

        $this->assertSame('p-1', $p->getId());
        $this->assertSame('Welcome', $p->getName());
        $this->assertSame('Lobby', $p->getLocationGroup());
        $this->assertSame('0 9 * * *', $p->getScheduleRule());

        $dto = PlaylistResponse::fromEntity($p);
        $this->assertSame('p-1', $dto->id);
    }

    public function testTerminalPackageTransferEntity(): void
    {
        [$org] = $this->makeOrgAndUser();
        $t = new Terminal('t-1', $org, 'T1', 'T', 'L');
        $tx = new TerminalPackageTransfer('tx-1', $org, $t, 'pkg.zip', 'sha', 10);

        $this->assertSame('tx-1', $tx->getId());
        $this->assertSame($org, $tx->getOrganization());
        $this->assertSame('org-1', $tx->getOrganizationId());
        $this->assertSame($t, $tx->getTerminal());
        $this->assertSame('pkg.zip', $tx->getPackageName());
        $this->assertSame('sha', $tx->getChecksum());
        $this->assertSame(10, $tx->getTotalChunks());

        $dto = TransferResponse::fromEntity($tx);
        $this->assertSame('tx-1', $dto->id);
    }

    public function testReconciliationRunEntity(): void
    {
        [$org] = $this->makeOrgAndUser();
        $r = new ReconciliationRun('rr-1', $org, new \DateTimeImmutable('2026-04-09'));

        $this->assertSame('rr-1', $r->getId());
        $this->assertSame($org, $r->getOrganization());
        $this->assertInstanceOf(\DateTimeImmutable::class, $r->getRunDate());

        $dto = ReconciliationRunResponse::fromEntity($r);
        $this->assertSame('rr-1', $dto->id);
    }
}
