<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Services;

use Modules\Shipment\Domain\Contracts\LocalDeliveryEligibilityInterface;

/**
 * Default, intentionally permissive local-delivery eligibility rule.
 *
 * When no allow-list is configured every address is eligible; otherwise the
 * address' province/city must appear in the configured allow-list. The precise
 * supported-region policy is handled separately — swap this binding to replace it
 * without touching controllers or Actions.
 */
class ConfigLocalDeliveryEligibility implements LocalDeliveryEligibilityInterface
{
    public function isEligible(?int $provinceId, ?int $cityId): bool
    {
        $provinces = config('shipment.local_delivery.province_ids');
        $cities = config('shipment.local_delivery.city_ids');

        if (empty($provinces) && empty($cities)) {
            return true;
        }

        if (! empty($cities) && $cityId !== null && in_array($cityId, $cities, true)) {
            return true;
        }

        if (! empty($provinces) && $provinceId !== null && in_array($provinceId, $provinces, true)) {
            return true;
        }

        return false;
    }
}
