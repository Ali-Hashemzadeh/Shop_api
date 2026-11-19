<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Shipment\Domain\DTOs\ShipmentMethodDTO;

/**
 * One fulfillment option. All money is integer TOMANS.
 *
 * Every pre-existing key is kept. Postal 1405 methods additionally fill
 * tariff_year / scope / package_kind / weight_grams / rate_zone / price_breakdown;
 * those keys are always present (null for local delivery and pickup) so the shape is
 * stable. `price` is null when a postal method cannot be priced — never 0.
 *
 * @mixin ShipmentMethodDTO
 */
class ShipmentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ShipmentMethodDTO $dto */
        $dto = $this->resource;

        $data = [
            'code' => $dto->code,
            'title' => $dto->title,
            'type' => $dto->type,
            /** @var int|null Total shipping price in tomans; null when unavailable postal. */
            'price' => $dto->price,
            'requires_address' => $dto->requiresAddress,
            'requires_delivery_slot' => $dto->requiresDeliverySlot,
            'supports_tracking' => $dto->supportsTracking,
            'estimated_min_days' => $dto->estimatedMinDays,
            'estimated_max_days' => $dto->estimatedMaxDays,
            'available' => $dto->available,
            'unavailable_reason' => $dto->unavailableReason,
            /** @var string|null Machine-readable reason, e.g. route_not_supported, weight_missing. */
            'unavailable_code' => $dto->unavailableCode,
            'currency' => 'TOMAN',
            'tariff_year' => $dto->tariffYear,
            /** @var string|null non_priority | specialized | urban | intercity */
            'scope' => $dto->scope,
            /** @var string|null envelope | packet | parcel | bag | bundle */
            'package_kind' => $dto->packageKind,
            'weight_grams' => $dto->weightGrams,
            /** @var string|null same_province | neighbor | non_neighbor | metropolis | province_center | other_city */
            'rate_zone' => $dto->rateZone,
            /** @var array{base_price: int, extra_weight: int, delivery_surcharge: int, large_province_surcharge: int, island_surcharge: int, fragile_surcharge: int, electronic_service_charge: int, compensation: int, postal_tax: int, packaging: int, total: int}|null */
            'price_breakdown' => $dto->priceBreakdown,
        ];

        if ($dto->missingWeightSkus !== []) {
            $data['missing_weight_skus'] = $dto->missingWeightSkus;
        }

        if ($dto->pickupLocation !== null) {
            $data['pickup_location'] = $dto->pickupLocation;
        }

        return $data;
    }
}
