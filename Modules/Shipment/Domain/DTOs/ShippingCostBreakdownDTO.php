<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

/**
 * The itemised result of a shipping cost calculation. `total` is the payable amount
 * in TOMANS (integer, Money Unit Rule); the components are exposed for transparency,
 * logging, and admin display. Every field is a whole toman — no float ever appears.
 */
class ShippingCostBreakdownDTO
{
    public function __construct(
        public readonly int $total,
        public readonly string $distanceType,
        public readonly int $basePrice,
        public readonly int $extraWeightCost,
        public readonly int $islandSurcharge,
        public readonly int $largeProvinceSurcharge,
        public readonly int $tehranSurcharge,
        public readonly int $fragileSurcharge,
        public readonly int $weightGrams,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'distance_type' => $this->distanceType,
            'base_price' => $this->basePrice,
            'extra_weight_cost' => $this->extraWeightCost,
            'island_surcharge' => $this->islandSurcharge,
            'large_province_surcharge' => $this->largeProvinceSurcharge,
            'tehran_surcharge' => $this->tehranSurcharge,
            'fragile_surcharge' => $this->fragileSurcharge,
            'weight_grams' => $this->weightGrams,
        ];
    }
}
