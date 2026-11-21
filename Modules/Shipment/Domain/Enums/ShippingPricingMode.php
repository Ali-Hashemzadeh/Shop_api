<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Enums;

/**
 * How a fulfillment method's price is produced. Exposed as `pricing_mode` on every
 * method in GET /shipment/methods, and stored in the order's shipment snapshot for
 * the Normal Post fallback so it is never mistaken for an official tariff quote.
 */
enum ShippingPricingMode: string
{
    /** Weight-based Iran Post 1405 tariff engine. */
    case Post1405Tariff = 'post_1405_tariff';

    /** Shop-defined flat Normal Post price for a cart with a missing item weight. */
    case FixedMissingWeightFallback = 'fixed_missing_weight_fallback';

    /** Static configured price (local delivery, pickup). */
    case StaticPrice = 'static';
}
