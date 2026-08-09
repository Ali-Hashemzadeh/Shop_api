<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Enums;

/**
 * What a discount is allowed to apply to.
 *
 * TARGETED means "only the entities named in discount_targets" and is the only
 * scope an automatic discount may use. ALL means "the whole post-automatic
 * merchandise subtotal" and is the only scope a coupon discount may use — it is
 * not a store-wide automatic sale, because a coupon rule does nothing until its
 * code is supplied.
 */
enum DiscountScope: string
{
    case TARGETED = 'targeted';
    case ALL = 'all';
}
