<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

/**
 * Immutable input to a shipping cost calculator. Carries only primitives — the
 * calculator never sees an Order, a Cart, or an address model.
 *
 * Geography is passed as-is (nullable) so the calculator itself validates it and
 * fails closed: a missing origin/destination never silently classifies as
 * "non-neighbour" and never produces a quote.
 */
class ShippingCostRequestDTO
{
    public function __construct(
        // The 1405 method code, e.g. post_intercity_d3_parcel.
        public readonly string $serviceCode,
        // Total parcel weight in whole grams (already validated as fully known).
        public readonly int $weightGrams,
        public readonly ?int $originProvinceId,
        public readonly ?int $originCityId,
        public readonly ?int $destinationProvinceId,
        public readonly ?int $destinationCityId,
        // Whether the shipment is classified fragile / liquid (PDF p.4 T5 r1).
        public readonly bool $fragile = false,
        // What the shipment physically is (Post1405Catalog::CONTENT_*).
        public readonly string $contentKind = 'goods',
        // The shop's declared carton number, when known.
        public readonly ?int $cartonSize = null,
    ) {}
}
