<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Repositories;

use Illuminate\Support\Carbon;
use Modules\Shipment\Domain\Models\PostTariff;

/**
 * Finds the single Post tariff bracket that applies to a parcel.
 *
 * Matching rules:
 *   - service_type + package_type + distance_type must all match exactly
 *   - the weight falls in the half-open bracket [weight_from, weight_to)
 *     (weight_to = null is the open-ended top bracket)
 *   - the tariff is in effect on $on (effective_from/until, null = unbounded)
 * When two brackets could match a boundary weight, the more specific (higher
 * weight_from) row wins.
 */
class PostTariffRepository
{
    public function findMatching(
        string $serviceType,
        string $packageType,
        string $distanceType,
        int $weightGrams,
        ?Carbon $on = null,
    ): ?PostTariff {
        $on ??= Carbon::now();
        $date = $on->toDateString();

        return PostTariff::query()
            ->where('service_type', $serviceType)
            ->where('package_type', $packageType)
            ->where('distance_type', $distanceType)
            ->where('weight_from', '<=', $weightGrams)
            ->where(function ($query) use ($weightGrams) {
                $query->whereNull('weight_to')
                    ->orWhere('weight_to', '>', $weightGrams);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $date);
            })
            ->orderByDesc('weight_from')
            ->first();
    }
}
