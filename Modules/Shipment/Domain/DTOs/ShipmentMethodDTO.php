<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

/**
 * One fulfillment option for the method list.
 *
 * `price` is nullable: a postal method that cannot be priced (unavailable route,
 * missing weight, missing tariff) reports `price = null` — never 0 and never a static
 * fallback. Non-postal methods always carry their configured integer price.
 *
 * The one fixed-price postal option is the Normal Post fallback (`post_standard`,
 * pricingMode = fixed_missing_weight_fallback), offered only to a cart with a
 * missing item weight; it has no tariff year, weight or rate zone.
 */
class ShipmentMethodDTO
{
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $type,
        public readonly ?int $price,
        public readonly bool $requiresAddress,
        public readonly bool $requiresDeliverySlot,
        public readonly bool $supportsTracking,
        public readonly ?int $estimatedMinDays,
        public readonly ?int $estimatedMaxDays,
        public readonly bool $available = true,
        public readonly ?string $unavailableReason = null,
        public readonly ?array $pickupLocation = null,
        public readonly ?string $unavailableCode = null,
        public readonly ?int $tariffYear = null,
        public readonly ?string $scope = null,
        public readonly ?string $packageKind = null,
        public readonly ?int $weightGrams = null,
        public readonly ?string $rateZone = null,
        /** @var array<string, int>|null */
        public readonly ?array $priceBreakdown = null,
        /** @var list<string> SKUs without a shipping weight (WeightMissing only) */
        public readonly array $missingWeightSkus = [],
        /** ShippingPricingMode value: post_1405_tariff | fixed_missing_weight_fallback | static */
        public readonly ?string $pricingMode = null,
    ) {}
}
