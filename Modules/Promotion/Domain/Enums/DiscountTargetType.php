<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Enums;

/**
 * The kind of Catalog entity a targeted discount points at.
 *
 * There is deliberately no `all` case: store-wide reach is expressed by
 * DiscountScope::ALL on the discount itself, never by a magic target row.
 *
 * The order of the cases *is* the tie-break order used when two automatic
 * discounts produce the identical rial reduction — most specific first.
 */
enum DiscountTargetType: string
{
    case VARIANT = 'variant';
    case PRODUCT = 'product';
    case CATEGORY = 'category';
    case BRAND = 'brand';

    /**
     * Lower rank == more specific == wins an otherwise exact tie.
     *
     * Specificity is *only* a tie-breaker. A product-targeted discount that saves
     * the customer more always beats a variant-targeted one that saves less.
     */
    public function specificityRank(): int
    {
        return match ($this) {
            self::VARIANT => 0,
            self::PRODUCT => 1,
            self::CATEGORY => 2,
            self::BRAND => 3,
        };
    }
}
