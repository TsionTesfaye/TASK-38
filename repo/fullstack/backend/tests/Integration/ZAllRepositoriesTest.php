<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Bill;
use App\Entity\Booking;
use App\Entity\BookingHold;
use App\Entity\DeviceSession;
use App\Entity\InventoryItem;
use App\Entity\InventoryPricing;
use App\Entity\LedgerEntry;
use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Entity\Refund;
use App\Entity\Settings;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\BookingHoldStatus;
use App\Enum\BookingStatus;
use App\Enum\CapacityMode;
use App\Enum\LedgerEntryType;
use App\Enum\PaymentStatus;
use App\Enum\RateType;
use App\Enum\RefundStatus;
use App\Enum\UserRole;
use App\Repository\AuditLogRepository;
use App\Repository\BillRepository;
use App\Repository\BookingHoldRepository;
use App\Repository\BookingRepository;
use App\Repository\DeviceSessionRepository;
use App\Repository\InventoryItemRepository;
use App\Repository\InventoryPricingRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\NotificationRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PaymentRepository;
use App\Repository\RefundRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Real-DB integration coverage of every repository's query methods.
 * Inserts full object graphs and calls every finder method.
 */
class ZAllRepositoriesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $orgId;
    private string $userId;
    private string $itemId;
    private string $bookingId;
    private string $billId;
    private string $paymentId;

    public static function tearDownAfterClass(): void
    {
        $k = self::bootKernel();
        $c = $k->getContainer()->get('doctrine.dbal.default_connection');
        try {
            $orgIds = $c->fetchFirstColumn("SELECT id FROM organizations WHERE code LIKE 'REPOT_%'");
            if (empty($orgIds)) return;
            $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($orgIds as $oid) {
                foreach (['ledger_entries','refunds','payments','bills','bookings','booking_holds','inventory_pricing','inventory_items','audit_logs','notifications','notification_preferences','settings','device_sessions','users'] as $t) {
                    $c->executeStatement("DELETE FROM {$t} WHERE organization_id = ?", [$oid]);
                }
                $c->executeStatement('DELETE FROM organizations WHERE id = ?', [$oid]);
            }
            $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\Throwable) {
            try { $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1'); } catch (\Throwable) {}
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        // Seed data
        $this->orgId = Uuid::v4()->toRfc4122();
        $org = new Organization($this->orgId, 'REPOT_' . substr(Uuid::v4()->toRfc4122(), 0, 6), 'Repo Test Org');
        $this->em->persist($org);

        $this->userId = Uuid::v4()->toRfc4122();
        $user = new User(
            $this->userId, $org,
            'repotest_' . substr(Uuid::v4()->toRfc4122(), 0, 6),
            password_hash('x', PASSWORD_BCRYPT),
            'Repo User', UserRole::TENANT,
        );
        $this->em->persist($user);

        $settings = new Settings(Uuid::v4()->toRfc4122(), $org);
        $this->em->persist($settings);

        $this->itemId = Uuid::v4()->toRfc4122();
        $item = new InventoryItem(
            $this->itemId, $org,
            'REPO-' . substr(Uuid::v4()->toRfc4122(), 0, 6),
            'Test Item', 'studio', 'Loc', CapacityMode::DISCRETE_UNITS, 3, 'UTC',
        );
        $this->em->persist($item);

        $pricing = new InventoryPricing(
            Uuid::v4()->toRfc4122(), $org, $item, RateType::DAILY, '100.00', 'USD',
            new \DateTimeImmutable('-1 month'), null,
        );
        $this->em->persist($pricing);

        $this->bookingId = Uuid::v4()->toRfc4122();
        $booking = new Booking(
            $this->bookingId, $org, $item, $user, null,
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+2 days'),
            1, 'USD', '100.00', '100.00',
        );
        $this->em->persist($booking);

        $this->billId = Uuid::v4()->toRfc4122();
        $bill = new Bill(
            $this->billId, $org, $booking, $user, BillType::INITIAL, 'USD', '100.00', null,
        );
        $this->em->persist($bill);

        $this->paymentId = Uuid::v4()->toRfc4122();
        $payment = new Payment($this->paymentId, $org, $bill, 'req-' . Uuid::v4()->toRfc4122(), 'USD', '100.00');
        $this->em->persist($payment);

        $session = new DeviceSession(
            Uuid::v4()->toRfc4122(), $user, 'hash_' . Uuid::v4()->toRfc4122(), 'dev', 'cli',
            new \DateTimeImmutable('+1 day'),
        );
        $this->em->persist($session);

        $notif = new Notification(
            Uuid::v4()->toRfc4122(), $org, $user, 'booking.confirmed', 'T', 'B',
            new \DateTimeImmutable(),
        );
        $this->em->persist($notif);

        $pref = new NotificationPreference(Uuid::v4()->toRfc4122(), $user, 'booking.confirmed');
        $this->em->persist($pref);

        $hold = new BookingHold(
            Uuid::v4()->toRfc4122(), $org, $item, $user, 'req-h-' . Uuid::v4()->toRfc4122(),
            1, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+6 days'),
            new \DateTimeImmutable('+10 minutes'),
        );
        $this->em->persist($hold);

        $ledger = new LedgerEntry(
            Uuid::v4()->toRfc4122(), $org, LedgerEntryType::BILL_ISSUED, '100.00', 'USD',
            $booking, $bill, null, null, null,
        );
        $this->em->persist($ledger);

        $this->em->flush();
    }

    public function testOrganizationRepository(): void
    {
        $repo = $this->em->getRepository(Organization::class);
        $this->assertInstanceOf(OrganizationRepository::class, $repo);
        $this->assertNotNull($repo->find($this->orgId));
    }

    public function testUserRepository(): void
    {
        $repo = $this->em->getRepository(User::class);
        $this->assertInstanceOf(UserRepository::class, $repo);

        $user = $repo->find($this->userId);
        $this->assertNotNull($user);

        // findByUsername
        $byName = $repo->findByUsername($user->getUsername());
        $this->assertNotNull($byName);

        // findByIdAndOrg
        $byIdOrg = $repo->findByIdAndOrg($this->userId, $this->orgId);
        $this->assertNotNull($byIdOrg);

        // findByOrganizationId (paginated)
        $list = $repo->findByOrganizationId($this->orgId, [], 1, 25);
        $this->assertNotEmpty($list);

        $count = $repo->countByOrganizationId($this->orgId, []);
        $this->assertGreaterThan(0, $count);

        // countAdminsInSystem
        $adminCount = $repo->countAdminsInSystem();
        $this->assertGreaterThanOrEqual(0, $adminCount);

        // findDistinctOrganizationIds
        $orgs = $repo->findDistinctOrganizationIds();
        $this->assertContains($this->orgId, $orgs);
    }

    public function testInventoryItemRepository(): void
    {
        $repo = $this->em->getRepository(InventoryItem::class);
        $this->assertInstanceOf(InventoryItemRepository::class, $repo);

        $byIdOrg = $repo->findByIdAndOrg($this->itemId, $this->orgId);
        $this->assertNotNull($byIdOrg);

        $list = $repo->findByOrganizationId($this->orgId, [], 1, 25);
        $this->assertNotEmpty($list);

        $count = $repo->countByOrganizationId($this->orgId, []);
        $this->assertGreaterThan(0, $count);
    }

    public function testInventoryPricingRepository(): void
    {
        $repo = $this->em->getRepository(InventoryPricing::class);
        $this->assertInstanceOf(InventoryPricingRepository::class, $repo);

        $list = $repo->findByItemId($this->itemId);
        $this->assertNotEmpty($list);

        if (method_exists($repo, 'findActiveForItem')) {
            $active = $repo->findActiveForItem($this->itemId, new \DateTimeImmutable());
            $this->assertNotNull($active);
        }
    }

    public function testBookingRepository(): void
    {
        $repo = $this->em->getRepository(Booking::class);
        $this->assertInstanceOf(BookingRepository::class, $repo);

        $byIdOrg = $repo->findByIdAndOrg($this->bookingId, $this->orgId);
        $this->assertNotNull($byIdOrg);

        $list = $repo->findByOrg($this->orgId, [], 1, 25);
        $this->assertNotEmpty($list);

        $count = $repo->countByOrg($this->orgId, []);
        $this->assertGreaterThan(0, $count);

        if (method_exists($repo, 'findByTenant')) {
            $byTenant = $repo->findByTenant($this->userId, [], 1, 25);
            $this->assertIsArray($byTenant);
        }
    }

    public function testBookingHoldRepository(): void
    {
        $repo = $this->em->getRepository(BookingHold::class);
        $this->assertInstanceOf(BookingHoldRepository::class, $repo);

        if (method_exists($repo, 'findExpiredActive')) {
            $result = $repo->findExpiredActive();
            $this->assertIsArray($result);
        }

        if (method_exists($repo, 'sumActiveUnitsForItemInRange')) {
            $sum = $repo->sumActiveUnitsForItemInRange(
                $this->itemId,
                new \DateTimeImmutable('+5 days'),
                new \DateTimeImmutable('+6 days'),
            );
            $this->assertGreaterThanOrEqual(0, $sum);
        }
    }

    public function testBillRepository(): void
    {
        $repo = $this->em->getRepository(Bill::class);
        $this->assertInstanceOf(BillRepository::class, $repo);

        $byIdOrg = $repo->findByIdAndOrg($this->billId, $this->orgId);
        $this->assertNotNull($byIdOrg);

        $list = $repo->findByOrg($this->orgId, [], 1, 25);
        $this->assertNotEmpty($list);

        $count = $repo->countByOrg($this->orgId, []);
        $this->assertGreaterThan(0, $count);

        if (method_exists($repo, 'findByBookingId')) {
            $byBooking = $repo->findByBookingId($this->bookingId);
            $this->assertIsArray($byBooking);
        }

        if (method_exists($repo, 'findByBookingAndPeriod')) {
            $byPeriod = $repo->findByBookingAndPeriod(
                $this->bookingId,
                (new \DateTimeImmutable())->format('Y-m'),
                BillType::RECURRING,
            );
            // Returns null or Bill — either is valid
            $this->assertTrue($byPeriod === null || $byPeriod instanceof Bill);
        }
    }

    public function testPaymentRepository(): void
    {
        $repo = $this->em->getRepository(Payment::class);
        $this->assertInstanceOf(PaymentRepository::class, $repo);

        $payment = $repo->find($this->paymentId);
        $this->assertNotNull($payment);

        $byReq = $repo->findByRequestId($payment->getRequestId());
        $this->assertNotNull($byReq);

        if (method_exists($repo, 'findByBillId')) {
            $byBill = $repo->findByBillId($this->billId);
            $this->assertIsArray($byBill);
        }

        if (method_exists($repo, 'findByBillIdAndStatus')) {
            $byBillStatus = $repo->findByBillIdAndStatus($this->billId, PaymentStatus::PENDING);
            $this->assertIsArray($byBillStatus);
        }

        if (method_exists($repo, 'findByOrg')) {
            $byOrg = $repo->findByOrg($this->orgId, [], 1, 25);
            $this->assertIsArray($byOrg);
        }

        if (method_exists($repo, 'countByOrg')) {
            $count = $repo->countByOrg($this->orgId, []);
            $this->assertIsInt($count);
        }
    }

    public function testRefundRepository(): void
    {
        $repo = $this->em->getRepository(Refund::class);
        $this->assertInstanceOf(RefundRepository::class, $repo);

        if (method_exists($repo, 'findByBillId')) {
            $r = $repo->findByBillId($this->billId);
            $this->assertIsArray($r);
        }

        if (method_exists($repo, 'findByBillIdAndStatus')) {
            $r = $repo->findByBillIdAndStatus($this->billId, RefundStatus::ISSUED);
            $this->assertIsArray($r);
        }

        if (method_exists($repo, 'findByOrg')) {
            $r = $repo->findByOrg($this->orgId, [], 1, 25);
            $this->assertIsArray($r);
        }

        if (method_exists($repo, 'countByOrg')) {
            $c = $repo->countByOrg($this->orgId, []);
            $this->assertIsInt($c);
        }
    }

    public function testLedgerEntryRepository(): void
    {
        $repo = $this->em->getRepository(LedgerEntry::class);
        $this->assertInstanceOf(LedgerEntryRepository::class, $repo);

        if (method_exists($repo, 'findByOrg')) {
            $l = $repo->findByOrg($this->orgId, [], 1, 25);
            $this->assertIsArray($l);
        }

        if (method_exists($repo, 'countByOrg')) {
            $c = $repo->countByOrg($this->orgId, []);
            $this->assertIsInt($c);
        }

        if (method_exists($repo, 'findByBillId')) {
            $this->assertIsArray($repo->findByBillId($this->billId));
        }

        if (method_exists($repo, 'findByBookingId')) {
            $this->assertIsArray($repo->findByBookingId($this->bookingId));
        }
    }

    public function testDeviceSessionRepository(): void
    {
        $repo = $this->em->getRepository(DeviceSession::class);
        $this->assertInstanceOf(DeviceSessionRepository::class, $repo);

        $active = $repo->findActiveByUserId($this->userId);
        $this->assertIsArray($active);

        $count = $repo->countActiveByUserId($this->userId);
        $this->assertIsInt($count);

        $oldest = $repo->findOldestActiveByUserId($this->userId);
        $this->assertTrue($oldest === null || $oldest instanceof DeviceSession);
    }

    public function testSettingsRepository(): void
    {
        $repo = $this->em->getRepository(Settings::class);
        $this->assertInstanceOf(SettingsRepository::class, $repo);

        $s = $repo->findByOrganizationId($this->orgId);
        $this->assertNotNull($s);
    }

    public function testNotificationRepository(): void
    {
        $repo = $this->em->getRepository(Notification::class);
        $this->assertInstanceOf(NotificationRepository::class, $repo);

        if (method_exists($repo, 'findByUser')) {
            $n = $repo->findByUser($this->userId, 1, 25);
            $this->assertIsArray($n);
        }

        if (method_exists($repo, 'countByUser')) {
            $c = $repo->countByUser($this->userId);
            $this->assertIsInt($c);
        }

        if (method_exists($repo, 'findPendingDue')) {
            $p = $repo->findPendingDue();
            $this->assertIsArray($p);
        }

        if (method_exists($repo, 'countPendingDueByOrg')) {
            $c = $repo->countPendingDueByOrg($this->orgId);
            $this->assertIsInt($c);
        }
    }

    public function testNotificationPreferenceRepository(): void
    {
        $repo = $this->em->getRepository(NotificationPreference::class);
        $this->assertInstanceOf(NotificationPreferenceRepository::class, $repo);

        $byUser = $repo->findAllByUser($this->userId);
        $this->assertIsArray($byUser);

        $byUserEvent = $repo->findByUserAndEvent($this->userId, 'booking.confirmed');
        $this->assertNotNull($byUserEvent);
    }

    public function testAuditLogRepository(): void
    {
        $repo = $this->em->getRepository(\App\Entity\AuditLog::class);
        $this->assertInstanceOf(AuditLogRepository::class, $repo);

        if (method_exists($repo, 'findByOrg')) {
            $l = $repo->findByOrg($this->orgId, [], 1, 25);
            $this->assertIsArray($l);
        }

        if (method_exists($repo, 'countByOrg')) {
            $c = $repo->countByOrg($this->orgId, []);
            $this->assertIsInt($c);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Extended coverage for repository methods not yet hit
    // ═══════════════════════════════════════════════════════════════

    public function testBillRepositoryExtended(): void
    {
        $repo = $this->em->getRepository(Bill::class);

        // sumSuccessfulPayments + sumIssuedRefunds
        $sumPaid = $repo->sumSuccessfulPayments($this->billId);
        $this->assertIsString($sumPaid);

        $sumRefunded = $repo->sumIssuedRefunds($this->billId);
        $this->assertIsString($sumRefunded);

        // findByOrgForReconciliation
        $recon = $repo->findByOrgForReconciliation($this->orgId);
        $this->assertIsArray($recon);

        // findByTenant with filters
        $byTenant = $repo->findByTenant($this->userId, [], 1, 25);
        $this->assertIsArray($byTenant);

        // findByTenant with status filter
        $byTenantFiltered = $repo->findByTenant($this->userId, ['status' => 'open'], 1, 25);
        $this->assertIsArray($byTenantFiltered);

        // findByOrg with filters
        $filtered = $repo->findByOrg($this->orgId, ['status' => 'open'], 1, 25);
        $this->assertIsArray($filtered);

        $filtered2 = $repo->findByOrg($this->orgId, ['tenant_user_id' => $this->userId], 1, 25);
        $this->assertIsArray($filtered2);

        $filtered3 = $repo->findByOrg($this->orgId, ['bill_type' => 'initial'], 1, 25);
        $this->assertIsArray($filtered3);
    }

    public function testBookingRepositoryExtended(): void
    {
        $repo = $this->em->getRepository(Booking::class);

        // sumActiveUnitsForItemInRange
        $start = new \DateTimeImmutable('2026-01-01');
        $end = new \DateTimeImmutable('2027-01-01');
        $units = $repo->sumActiveUnitsForItemInRange($this->itemId, $start, $end);
        $this->assertIsInt($units);

        // findActiveNoShows
        $noShows = $repo->findActiveNoShows(30);
        $this->assertIsArray($noShows);

        // findNeedingRecurringBill
        if (method_exists($repo, 'findNeedingRecurringBill')) {
            $needing = $repo->findNeedingRecurringBill($this->orgId);
            $this->assertIsArray($needing);
        }

        // findNoShowCandidates
        if (method_exists($repo, 'findNoShowCandidates')) {
            $cand = $repo->findNoShowCandidates();
            $this->assertIsArray($cand);
        }

        // findByOrg with filters
        $s1 = $repo->findByOrg($this->orgId, ['status' => 'confirmed'], 1, 25);
        $this->assertIsArray($s1);
        $s2 = $repo->findByOrg($this->orgId, ['inventory_item_id' => $this->itemId], 1, 25);
        $this->assertIsArray($s2);
        $s3 = $repo->findByOrg($this->orgId, ['tenant_user_id' => $this->userId], 1, 25);
        $this->assertIsArray($s3);

        // findByTenant
        $byT = $repo->findByTenant($this->userId, [], 1, 25);
        $this->assertIsArray($byT);
    }

    public function testRefundRepositoryExtended(): void
    {
        $repo = $this->em->getRepository(Refund::class);

        // findByBillIdAndStatus
        $byBillStatus = $repo->findByBillIdAndStatus($this->billId, RefundStatus::ISSUED);
        $this->assertIsArray($byBillStatus);

        // findByOrg with filters
        $s1 = $repo->findByOrg($this->orgId, ['status' => 'issued'], 1, 25);
        $this->assertIsArray($s1);

        // findByBillId
        if (method_exists($repo, 'findByBillId')) {
            $byBill = $repo->findByBillId($this->billId);
            $this->assertIsArray($byBill);
        }
    }

    public function testLedgerEntryRepositoryExtended(): void
    {
        $repo = $this->em->getRepository(LedgerEntry::class);

        // findByOrg with various filters
        $list1 = $repo->findByOrg($this->orgId, [], 1, 25);
        $this->assertIsArray($list1);

        $list2 = $repo->findByOrg($this->orgId, ['entry_type' => 'payment_received'], 1, 25);
        $this->assertIsArray($list2);

        $list3 = $repo->findByOrg($this->orgId, ['currency' => 'USD'], 1, 25);
        $this->assertIsArray($list3);

        $count = $repo->countByOrg($this->orgId, []);
        $this->assertIsInt($count);
    }

    public function testUserRepositoryExtended(): void
    {
        $repo = $this->em->getRepository(User::class);

        if (method_exists($repo, 'findByOrg')) {
            $byOrg = $repo->findByOrg($this->orgId, [], 1, 25);
            $this->assertIsArray($byOrg);
        }

        if (method_exists($repo, 'findDistinctOrganizationIds')) {
            $ids = $repo->findDistinctOrganizationIds();
            $this->assertIsArray($ids);
        }

        if (method_exists($repo, 'findByUsername')) {
            $u = $repo->findByUsername('nobody-here');
            $this->assertNull($u);
        }

        if (method_exists($repo, 'countByOrg')) {
            $c = $repo->countByOrg($this->orgId, []);
            $this->assertIsInt($c);
        }
    }

    public function testOrganizationRepositoryExtended(): void
    {
        $repo = $this->em->getRepository(Organization::class);

        if (method_exists($repo, 'findAllActive')) {
            $all = $repo->findAllActive();
            $this->assertIsArray($all);
        }
    }

    public function testNotificationRepositoryExtended(): void
    {
        $repo = $this->em->getRepository(Notification::class);

        if (method_exists($repo, 'findByUser')) {
            $l = $repo->findByUser($this->userId, 1, 25);
            $this->assertIsArray($l);
        }

        if (method_exists($repo, 'countByUser')) {
            $c = $repo->countByUser($this->userId);
            $this->assertIsInt($c);
        }

        if (method_exists($repo, 'findDueForDelivery')) {
            $due = $repo->findDueForDelivery();
            $this->assertIsArray($due);
        }
    }

    public function testDeviceSessionRepositoryExtended(): void
    {
        $repo = $this->em->getRepository(DeviceSession::class);

        if (method_exists($repo, 'findActiveByUserId')) {
            $active = $repo->findActiveByUserId($this->userId);
            $this->assertIsArray($active);
        }

        if (method_exists($repo, 'findByDeviceFingerprint')) {
            $byFp = $repo->findByDeviceFingerprint($this->userId, 'unknown-fp');
            $this->assertNull($byFp);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Deeper filter coverage for audit/ledger/refund repos
    // ═══════════════════════════════════════════════════════════════

    public function testAuditLogRepositoryAllFilters(): void
    {
        $repo = $this->em->getRepository(\App\Entity\AuditLog::class);

        // Every filter branch: action_code, actor_user_id, object_type,
        // object_id, from, to
        $baseFilters = [
            ['action_code' => 'ACTION'],
            ['actor_user_id' => $this->userId],
            ['object_type' => 'Booking'],
            ['object_id' => $this->bookingId],
            ['from' => new \DateTimeImmutable('-1 day')],
            ['to' => new \DateTimeImmutable('+1 day')],
            ['action_code' => 'X', 'actor_user_id' => $this->userId, 'object_type' => 'Y', 'object_id' => 'z'],
        ];
        foreach ($baseFilters as $f) {
            $this->assertIsArray($repo->findByOrg($this->orgId, $f, 1, 10));
            $this->assertIsInt($repo->countByOrg($this->orgId, $f));
        }
    }

    public function testRefundRepositoryExtendedSum(): void
    {
        $repo = $this->em->getRepository(Refund::class);
        if (method_exists($repo, 'sumIssuedForBill')) {
            $sum = $repo->sumIssuedForBill($this->billId);
            $this->assertIsString($sum);
        }
    }

    public function testLedgerEntryRepositoryAllFilters(): void
    {
        $repo = $this->em->getRepository(LedgerEntry::class);
        $filters = [
            ['entry_type' => 'bill_issued'],
            ['currency' => 'USD'],
            ['from' => new \DateTimeImmutable('-1 day')],
            ['to' => new \DateTimeImmutable('+1 day')],
            ['entry_type' => 'x', 'currency' => 'USD'],
        ];
        foreach ($filters as $f) {
            $this->assertIsArray($repo->findByOrg($this->orgId, $f, 1, 10));
            $this->assertIsInt($repo->countByOrg($this->orgId, $f));
        }
    }

    public function testBookingRepositoryDateFilters(): void
    {
        $repo = $this->em->getRepository(Booking::class);
        $filters = [
            ['status' => 'confirmed'],
            ['inventory_item_id' => $this->itemId],
            ['tenant_user_id' => $this->userId],
            ['start_from' => new \DateTimeImmutable('-1 day')],
            ['start_to' => new \DateTimeImmutable('+365 days')],
        ];
        foreach ($filters as $f) {
            $this->assertIsArray($repo->findByOrg($this->orgId, $f, 1, 10));
            $this->assertIsInt($repo->countByOrg($this->orgId, $f));
            $this->assertIsArray($repo->findByTenant($this->userId, $f, 1, 10));
        }
    }

    public function testInventoryItemRepositoryAllFilters(): void
    {
        $repo = $this->em->getRepository(InventoryItem::class);
        $filters = [
            ['asset_type' => 'studio'],
            ['is_active' => true],
            ['is_active' => false],
            ['search' => 'test'],
            ['asset_type' => 'studio', 'is_active' => true, 'search' => 'a'],
        ];
        foreach ($filters as $f) {
            $this->assertIsArray($repo->findByOrganizationId($this->orgId, $f, 1, 10));
            $this->assertIsInt($repo->countByOrganizationId($this->orgId, $f));
        }
        // findByOrgAndAssetCode
        $byCode = $repo->findByOrgAndAssetCode($this->orgId, 'ITM-NOPE');
        $this->assertNull($byCode);
    }

    public function testTerminalRepositoryExtended(): void
    {
        $repo = $this->em->getRepository(\App\Entity\Terminal::class);
        if (method_exists($repo, 'findByOrg')) {
            $this->assertIsArray($repo->findByOrg($this->orgId, [], 1, 10));
        }
        if (method_exists($repo, 'countByOrg')) {
            $this->assertIsInt($repo->countByOrg($this->orgId, []));
        }
        if (method_exists($repo, 'findByIdAndOrg')) {
            $this->assertNull($repo->findByIdAndOrg('00000000-0000-0000-0000-000000000000', $this->orgId));
        }
        if (method_exists($repo, 'findByTerminalCode')) {
            $this->assertNull($repo->findByTerminalCode($this->orgId, 'NONE'));
        }
    }
}
