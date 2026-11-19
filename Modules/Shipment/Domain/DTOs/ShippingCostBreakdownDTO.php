<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

/**
 * The itemised result of a 1405 postal quote. Every amount is a whole TOMAN (Money
 * Unit Rule — no float ever appears) and `total` is exactly the sum of the parts:
 *
 *   total = base_price + extra_weight                         (postal service charge)
 *         + delivery_surcharge + large_province_surcharge
 *         + island_surcharge + fragile_surcharge
 *         + electronic_service_charge + compensation
 *         + postal_tax                                         (10 % of the taxable parts, floored)
 *         + packaging                                          (flat shop fee, untaxed)
 */
class ShippingCostBreakdownDTO
{
    public function __construct(
        public readonly string $serviceCode,
        public readonly int $tariffYear,
        public readonly string $rateZone,
        public readonly int $weightGrams,
        public readonly int $basePrice,
        public readonly int $extraWeight,
        public readonly int $deliverySurcharge,
        // 'tehran_alborz' | 'metropolitan' | null — which delivery-area rule applied.
        public readonly ?string $deliverySurchargeRule,
        public readonly int $largeProvinceSurcharge,
        public readonly int $islandSurcharge,
        public readonly int $fragileSurcharge,
        public readonly int $electronicServiceCharge,
        public readonly int $compensation,
        public readonly int $postalTax,
        public readonly int $packaging,
    ) {}

    public function postalServiceCharge(): int
    {
        return $this->basePrice + $this->extraWeight;
    }

    public function total(): int
    {
        return $this->postalServiceCharge()
            + $this->deliverySurcharge
            + $this->largeProvinceSurcharge
            + $this->islandSurcharge
            + $this->fragileSurcharge
            + $this->electronicServiceCharge
            + $this->compensation
            + $this->postalTax
            + $this->packaging;
    }

    /**
     * The public `price_breakdown` shape (method list, order snapshot, shipment).
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'base_price' => $this->basePrice,
            'extra_weight' => $this->extraWeight,
            'delivery_surcharge' => $this->deliverySurcharge,
            'large_province_surcharge' => $this->largeProvinceSurcharge,
            'island_surcharge' => $this->islandSurcharge,
            'fragile_surcharge' => $this->fragileSurcharge,
            'electronic_service_charge' => $this->electronicServiceCharge,
            'compensation' => $this->compensation,
            'postal_tax' => $this->postalTax,
            'packaging' => $this->packaging,
            'total' => $this->total(),
        ];
    }
}
