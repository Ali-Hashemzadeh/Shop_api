<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Shipping\Post;

use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Shipment\Domain\Contracts\ShippingCostCalculatorInterface;
use Modules\Shipment\Domain\DTOs\ShippingCostBreakdownDTO;
use Modules\Shipment\Domain\DTOs\ShippingCostRequestDTO;
use Modules\Shipment\Domain\Enums\PackageType;
use Modules\Shipment\Domain\Exceptions\ShippingTariffNotFoundException;
use Modules\Shipment\Infrastructure\Persistence\Repositories\PostTariffRepository;
use Modules\Shipment\Infrastructure\Persistence\Repositories\ShippingParameterRepository;

/**
 * The Post tariff engine. Prices a parcel entirely in integer TOMANS (Money Unit
 * Rule — no float ever touches a price).
 *
 * Flow:
 *   1. Classify the distance via Identity (owns province adjacency).
 *   2. Find the matching tariff bracket (throws if none — fail-loud).
 *   3. Add per-kg extra weight (open-ended top bracket only).
 *   4. Add the flat island per-kg surcharge.
 *   5. Add each configurable percentage surcharge (large province, Tehran/Alborz,
 *      fragile), each computed on the post-weight-and-island subtotal so they never
 *      compound with one another.
 */
class PostShippingCalculator implements ShippingCostCalculatorInterface
{
    public function __construct(
        private readonly IdentityManagerInterface $identity,
        private readonly PostTariffRepository $tariffs,
        private readonly ShippingParameterRepository $parameters,
    ) {}

    public function calculate(ShippingCostRequestDTO $request): ShippingCostBreakdownDTO
    {
        $carrier = (string) config('shipping.carrier', 'post');

        $distanceType = $this->identity->getProvinceDistanceType(
            $request->originProvinceId,
            $request->destinationProvinceId,
        );

        $tariff = $this->tariffs->findMatching(
            serviceType: $request->serviceType,
            packageType: $request->packageType,
            distanceType: $distanceType,
            weightGrams: $request->weightGrams,
        );

        // Fragile (and any future package type) reuses the standard tariff brackets
        // when it has none of its own; the fragile surcharge is then added as a
        // percentage below. So a fragile parcel = standard base + fragile_percentage.
        if ($tariff === null && $request->packageType !== PackageType::Standard->value) {
            $tariff = $this->tariffs->findMatching(
                serviceType: $request->serviceType,
                packageType: PackageType::Standard->value,
                distanceType: $distanceType,
                weightGrams: $request->weightGrams,
            );
        }

        if ($tariff === null) {
            throw new ShippingTariffNotFoundException(sprintf(
                'No Post tariff for service=%s package=%s distance=%s weight=%dg.',
                $request->serviceType,
                $request->packageType,
                $distanceType,
                $request->weightGrams,
            ));
        }

        $basePrice = (int) $tariff->base_price;

        // Extra weight applies only on the open-ended top bracket, per kg rounded up,
        // for the weight above that bracket's floor.
        $extraWeightCost = 0;
        if ($tariff->weight_to === null && $tariff->extra_weight_price > 0) {
            $overGrams = max(0, $request->weightGrams - (int) $tariff->weight_from);
            $extraWeightCost = $this->kilogramsCeil($overGrams) * (int) $tariff->extra_weight_price;
        }

        // Flat per-kg island surcharge (rounded up).
        $islandSurcharge = 0;
        if ($this->inGroup($request->destinationProvinceId, 'islands')) {
            $islandSurcharge = $this->kilogramsCeil($request->weightGrams)
                * $this->parameters->integer($carrier, 'island_extra_per_kg', 0);
        }

        // Percentages are all computed on this subtotal, not on each other.
        $subtotal = $basePrice + $extraWeightCost + $islandSurcharge;

        $largeProvinceSurcharge = $this->inGroup($request->destinationProvinceId, 'large')
            ? $this->percentage($subtotal, $this->parameters->integer($carrier, 'large_province_percentage', 0))
            : 0;

        $tehranSurcharge = $this->inGroup($request->destinationProvinceId, 'tehran_alborz')
            ? $this->percentage($subtotal, $this->parameters->integer($carrier, 'tehran_percentage', 0))
            : 0;

        $fragileSurcharge = $request->packageType === PackageType::Fragile->value
            ? $this->percentage($subtotal, $this->parameters->integer($carrier, 'fragile_percentage', 0))
            : 0;

        $total = $subtotal + $largeProvinceSurcharge + $tehranSurcharge + $fragileSurcharge;

        return new ShippingCostBreakdownDTO(
            total: $total,
            distanceType: $distanceType,
            basePrice: $basePrice,
            extraWeightCost: $extraWeightCost,
            islandSurcharge: $islandSurcharge,
            largeProvinceSurcharge: $largeProvinceSurcharge,
            tehranSurcharge: $tehranSurcharge,
            fragileSurcharge: $fragileSurcharge,
            weightGrams: $request->weightGrams,
        );
    }

    /** Whole kilograms, rounded up. 0g stays 0kg. */
    private function kilogramsCeil(int $grams): int
    {
        return $grams <= 0 ? 0 : intdiv($grams + 999, 1000);
    }

    /** Integer percentage of an amount (floored) — never a float. */
    private function percentage(int $amount, int $percent): int
    {
        return $percent <= 0 ? 0 : intdiv($amount * $percent, 100);
    }

    private function inGroup(?int $provinceId, string $group): bool
    {
        if ($provinceId === null) {
            return false;
        }

        /** @var list<int> $ids */
        $ids = (array) config("shipping.province_groups.{$group}", []);

        return in_array($provinceId, $ids, true);
    }
}
