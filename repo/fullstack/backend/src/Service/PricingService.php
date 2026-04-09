<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\InventoryPricing;
use App\Entity\User;
use App\Enum\RateType;
use App\Exception\CurrencyMismatchException;
use App\Repository\InventoryPricingRepository;
use App\Repository\InventoryItemRepository;
use App\Repository\SettingsRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Validation\EnumValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class PricingService
{
    public function __construct(
        private readonly InventoryPricingRepository $pricingRepo,
        private readonly InventoryItemRepository $itemRepo,
        private readonly SettingsRepository $settingsRepo,
        private readonly EntityManagerInterface $em,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
    ) {}

    public function createPricing(User $manager, string $inventoryItemId, string $rateType, string $amount, string $currency, \DateTimeImmutable $effectiveFrom, ?\DateTimeImmutable $effectiveTo): InventoryPricing
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_INVENTORY);
        $orgId = $this->orgScope->getOrganizationId($manager);
        $item = $this->itemRepo->findByIdAndOrg($inventoryItemId, $orgId);
        if (!$item) { throw new \App\Exception\EntityNotFoundException('InventoryItem', $inventoryItemId); }

        $org = $manager->getOrganization();
        if ($currency !== $org->getDefaultCurrency()) { throw new CurrencyMismatchException(); }

        if ($this->pricingRepo->checkOverlap($inventoryItemId, $effectiveFrom, $effectiveTo)) {
            throw new \DomainException('Overlapping pricing range exists');
        }

        $pricing = new InventoryPricing(
            Uuid::v4()->toRfc4122(), $org, $item, EnumValidator::validate($rateType, RateType::class, 'rate_type'),
            $amount, $currency, $effectiveFrom, $effectiveTo,
        );
        $this->em->persist($pricing);
        $this->em->flush();
        return $pricing;
    }

    /**
     * Retrieve active pricing for an inventory item. Called only by service layer within org-validated context.
     * No controller exposes this method directly — controllers call createPricing or listPricing which have User scope.
     */
    public function getActivePricing(string $inventoryItemId, \DateTimeImmutable $at): ?InventoryPricing
    {
        return $this->pricingRepo->findActiveForItem($inventoryItemId, $at);
    }

    /**
     * Calculate booking amount from pricing. Called by BookingHoldService/BillingService within org-validated context.
     * Not directly exposed via any controller endpoint.
     */
    public function calculateBookingAmount(string $inventoryItemId, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt, int $units): string
    {
        $pricing = $this->getActivePricing($inventoryItemId, $startAt);
        if (!$pricing) { throw new \DomainException('No active pricing found for inventory item'); }

        $amount = $pricing->getAmount();
        $diff = $startAt->diff($endAt);

        $total = match ($pricing->getRateType()) {
            RateType::HOURLY => bcmul($amount, (string)max(1, (int)ceil($diff->h + $diff->days * 24)), 2),
            RateType::DAILY => bcmul($amount, (string)max(1, $diff->days), 2),
            RateType::MONTHLY => bcmul($amount, (string)max(1, (int)ceil($diff->days / 30)), 2),
            RateType::FLAT => $amount,
        };

        return bcmul($total, (string)$units, 2);
    }

    public function listPricing(User $user, string $inventoryItemId): array
    {
        // Pricing reads available to all authenticated org members (needed for booking cost display).
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_OWN);
        $orgId = $this->orgScope->getOrganizationId($user);
        $item = $this->itemRepo->findByIdAndOrg($inventoryItemId, $orgId);
        if (!$item) { throw new \App\Exception\EntityNotFoundException('InventoryItem', $inventoryItemId); }
        return $this->pricingRepo->findByItemId($inventoryItemId);
    }
}
