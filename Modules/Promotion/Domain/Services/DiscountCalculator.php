<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Services;

use Modules\Promotion\Domain\Enums\DiscountType;
use Modules\Promotion\Domain\Models\Discount;

/**
 * The only place a discount amount is ever computed.
 *
 * ── Integer-only arithmetic ───────────────────────────────────────────────────
 * Percentages are stored as basis points (1 bp = 1/100 of a percent), so 20% is
 * 2000 and 12.5% is 1250. A reduction is `intdiv($amount * $bps, 10000)`, which
 * multiplies *before* dividing so precision is never lost, and floors the result.
 *
 * Flooring is the deliberate, documented rounding strategy: it always rounds the
 * discount down, so the customer is never charged a sub-rial fraction more than
 * advertised and the store never gives away a rial it did not intend to. Because
 * it is pure integer division the answer is identical on every platform, every
 * run — no float rounding drift can make a cart total and an order total disagree.
 *
 * No PHP float ever appears in this class.
 */
class DiscountCalculator
{
    /** 100% expressed in basis points. */
    public const BPS_DIVISOR = 10000;

    /**
     * The rial reduction this rule produces against $amount.
     *
     * Always clamped to [0, $amount] so a discount can neither be negative nor
     * push a price below zero — a 100%-plus rule or an oversized fixed amount
     * simply lands the price on zero.
     */
    public function reductionFor(Discount $discount, int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        $raw = match ($discount->discount_type) {
            DiscountType::PERCENTAGE => $this->percentageOf($amount, (int) $discount->percentage_bps),
            DiscountType::FIXED_AMOUNT => (int) $discount->fixed_amount,
        };

        // The cap exists mainly for percentage rules ("20% off, up to 5,000,000")
        // but is honoured for fixed rules too rather than silently ignored.
        if ($discount->max_discount_amount !== null) {
            $raw = min($raw, (int) $discount->max_discount_amount);
        }

        return max(0, min($raw, $amount));
    }

    /**
     * Integer percentage: multiply first, then floor-divide.
     *
     * `intdiv(100_000_000 * 2000, 10000)` == 20_000_000 exactly. Doing it the
     * other way round — dividing first — would truncate to zero for small amounts.
     */
    public function percentageOf(int $amount, int $bps): int
    {
        if ($amount <= 0 || $bps <= 0) {
            return 0;
        }

        return intdiv($amount * $bps, self::BPS_DIVISOR);
    }

    /** The price left after applying this rule; never negative. */
    public function effectivePriceFor(Discount $discount, int $basePrice): int
    {
        return max(0, $basePrice - $this->reductionFor($discount, $basePrice));
    }
}
