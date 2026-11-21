<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Services;

use Modules\Shipment\Domain\DTOs\ShipmentParcelDTO;

/**
 * The single rule for the fixed-price Normal Post (پست عادی) fallback, shared by the
 * method list and checkout so both always agree.
 *
 * The 1405 engine refuses to price a parcel with an unknown item weight. When the
 * fallback is enabled, such a parcel is instead offered exactly one postal option —
 * the retired-from-normal-selection `post_standard` code — at a flat configured price,
 * and every weight-based 1405 method is withdrawn for it. A parcel whose weights are
 * all valid can never use the fallback.
 *
 * The method code is a constant, not configuration: pointing the fallback at a 1405
 * code (or at `post_express`) must never be one env edit away.
 */
final class MissingWeightFallbackPolicy
{
    public const METHOD_CODE = 'post_standard';

    public function isFallbackMethod(string $code): bool
    {
        return $code === self::METHOD_CODE;
    }

    /** Switched on AND a strictly positive price is configured (fail-closed otherwise). */
    public function isEnabled(): bool
    {
        return (bool) config('shipping.missing_weight_fallback.enabled', false) && $this->price() !== null;
    }

    /** The final flat customer charge in integer TOMANS, or null when not validly configured. */
    public function price(): ?int
    {
        $price = config('shipping.missing_weight_fallback.price_toman');

        return is_int($price) && $price > 0 ? $price : null;
    }

    /**
     * Whether this parcel is a fallback parcel: enabled, not empty, and at least one
     * item with a missing / zero / negative unit weight. Address eligibility is
     * checked separately, at quote time.
     */
    public function appliesTo(ShipmentParcelDTO $parcel): bool
    {
        return $this->isEnabled() && ! $parcel->isEmpty() && $parcel->skusMissingWeight() !== [];
    }
}
