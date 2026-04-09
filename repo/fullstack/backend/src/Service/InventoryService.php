<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\InventoryItem;
use App\Entity\User;
use App\Enum\CapacityMode;
use App\Enum\BookingHoldStatus;
use App\Enum\BookingStatus;
use App\Exception\EntityNotFoundException;
use App\Repository\InventoryItemRepository;
use App\Repository\BookingHoldRepository;
use App\Repository\BookingRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Validation\EnumValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class InventoryService
{
    public function __construct(
        private readonly InventoryItemRepository $itemRepo,
        private readonly BookingHoldRepository $holdRepo,
        private readonly BookingRepository $bookingRepo,
        private readonly EntityManagerInterface $em,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
        private readonly AuditService $auditService,
    ) {}

    public function createItem(User $manager, string $assetCode, string $name, string $assetType, string $locationName, string $capacityMode, int $totalCapacity, string $timezone): InventoryItem
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_INVENTORY);
        $org = $manager->getOrganization();
        $item = new InventoryItem(Uuid::v4()->toRfc4122(), $org, $assetCode, $name, $assetType, $locationName, EnumValidator::validate($capacityMode, CapacityMode::class, 'capacity_mode'), $totalCapacity, $timezone);
        $this->em->persist($item);
        $this->em->flush();
        $this->auditService->log($org->getId(), $manager, $manager->getUsername(), 'INVENTORY_CREATED', 'InventoryItem', $item->getId());
        return $item;
    }

    public function updateItem(User $manager, string $itemId, array $data): InventoryItem
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_INVENTORY);
        $orgId = $this->orgScope->getOrganizationId($manager);
        $item = $this->itemRepo->findByIdAndOrg($itemId, $orgId);
        if (!$item) { throw new EntityNotFoundException('InventoryItem', $itemId); }
        if (isset($data['name'])) { $item->setName($data['name']); }
        if (isset($data['location_name'])) { $item->setLocationName($data['location_name']); }
        if (isset($data['total_capacity'])) { $item->setTotalCapacity($data['total_capacity']); }
        if (isset($data['timezone'])) { $item->setTimezone($data['timezone']); }
        if (isset($data['is_active'])) { $item->setIsActive($data['is_active']); }
        $this->em->flush();
        return $item;
    }

    public function deactivateItem(User $manager, string $itemId): InventoryItem
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_INVENTORY);
        $orgId = $this->orgScope->getOrganizationId($manager);
        $item = $this->itemRepo->findByIdAndOrg($itemId, $orgId);
        if (!$item) { throw new EntityNotFoundException('InventoryItem', $itemId); }
        $item->setIsActive(false);
        $this->em->flush();
        $this->auditService->log($orgId, $manager, $manager->getUsername(), 'INVENTORY_DEACTIVATED', 'InventoryItem', $itemId);
        return $item;
    }

    public function getItem(User $user, string $itemId): InventoryItem
    {
        // Inventory reads are available to all authenticated org members (needed for booking flow).
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_OWN);
        $orgId = $this->orgScope->getOrganizationId($user);
        $item = $this->itemRepo->findByIdAndOrg($itemId, $orgId);
        if (!$item) { throw new EntityNotFoundException('InventoryItem', $itemId); }
        return $item;
    }

    public function listItems(User $user, array $filters, int $page, int $perPage): array
    {
        // Inventory listing is available to all authenticated org members (tenants browse to book).
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_OWN);
        $orgId = $this->orgScope->getOrganizationId($user);
        $page = max(1, $page);
        $perPage = min($perPage, 100);
        $items = $this->itemRepo->findByOrganizationId($orgId, $filters, $page, $perPage);
        $total = $this->itemRepo->countByOrganizationId($orgId, $filters);

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_next' => ($page * $perPage) < $total,
            ],
        ];
    }

    /**
     * @return array{available_units: int, requested_units: int, total_capacity: int, can_reserve: bool}
     */
    public function checkAvailability(User $user, string $itemId, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt, int $requestedUnits = 1): array
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_OWN);
        $orgId = $this->orgScope->getOrganizationId($user);
        $item = $this->itemRepo->findByIdAndOrg($itemId, $orgId);
        if (!$item) { throw new EntityNotFoundException('InventoryItem', $itemId); }

        $available = $this->calculateAvailableUnits($item, $startAt, $endAt);

        return [
            'available_units' => $available,
            'requested_units' => $requestedUnits,
            'total_capacity' => $item->getTotalCapacity(),
            'can_reserve' => $requestedUnits > 0 && $requestedUnits <= $available,
        ];
    }

    /** Called by BookingHoldService within same-org validated context. Not accessible from controllers. */
    private function checkAvailabilityForItem(InventoryItem $item, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt): int
    {
        return $this->calculateAvailableUnits($item, $startAt, $endAt);
    }

    private function calculateAvailableUnits(InventoryItem $item, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt): int
    {
        $heldUnits = $this->holdRepo->sumActiveUnitsForItemInRange($item->getId(), $startAt, $endAt);
        $bookedUnits = $this->bookingRepo->sumActiveUnitsForItemInRange($item->getId(), $startAt, $endAt);
        return max(0, $item->getTotalCapacity() - $heldUnits - $bookedUnits);
    }

    public function getAvailabilityCalendar(User $user, string $itemId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $orgId = $this->orgScope->getOrganizationId($user);
        $item = $this->itemRepo->findByIdAndOrg($itemId, $orgId);
        if (!$item) { throw new EntityNotFoundException('InventoryItem', $itemId); }
        $calendar = [];
        $current = $from;
        while ($current <= $to) {
            $dayEnd = $current->modify('+1 day');
            $available = $this->calculateAvailableUnits($item, $current, $dayEnd);
            $calendar[] = ['date' => $current->format('Y-m-d'), 'available_units' => $available, 'total_capacity' => $item->getTotalCapacity()];
            $current = $dayEnd;
        }
        return $calendar;
    }
}
