<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Enums;

/**
 * How a discount becomes eligible to reduce a price.
 *
 * AUTOMATIC rules evaluate against product context alone and are always live for
 * matching variants. COUPON rules are completely dormant until a customer supplies
 * a valid code — a coupon-backed discount never affects the storefront.
 */
enum DiscountTriggerType: string
{
    case AUTOMATIC = 'automatic';
    case COUPON = 'coupon';
}
