<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Enums;

/**
 * How the reduction is expressed. Percentages are stored as integer basis points
 * (see DiscountCalculator); fixed amounts are integer rials. Floats never appear
 * on either side of the calculation.
 */
enum DiscountType: string
{
    case PERCENTAGE = 'percentage';
    case FIXED_AMOUNT = 'fixed_amount';
}
