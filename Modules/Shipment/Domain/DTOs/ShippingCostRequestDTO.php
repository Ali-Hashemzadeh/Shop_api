<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

/**
 * Immutable input to a shipping cost calculator. Carries only primitives — the
 * calculator never sees an Order, a Cart, or an address model.
 */
class ShippingCostRequestDTO
{
    public function __construct(
        // The province the store dispatches from.
        public readonly ?int $originProvinceId,
        // The destination province (from the frozen address snapshot).
        public readonly ?int $destinationProvinceId,
        // Total parcel weight in whole grams.
        public readonly int $weightGrams,
        // The shipment method code, e.g. post_standard / post_express.
        public readonly string $serviceType,
        // Package classification, e.g. standard / fragile.
        public readonly string $packageType = 'standard',
    ) {}
}
