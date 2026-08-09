<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Services;

use Modules\Promotion\Domain\DTOs\AutomaticDiscountContextDTO;
use Modules\Promotion\Domain\DTOs\AutomaticDiscountResultDTO;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Models\Discount;

/**
 * Picks the single automatic discount that applies to a variant.
 *
 * ── The rule ──────────────────────────────────────────────────────────────────
 * A variant may match a variant-level rule, several product-level rules, several
 * category-level rules (including ones aimed at an ancestor category), and several
 * brand-level rules all at once. Every candidate is costed in actual rials and the
 * one that saves the customer the most wins. The rest do nothing — automatic
 * discounts never stack.
 *
 * ── Specificity is only a tie-breaker ─────────────────────────────────────────
 * A 30% product rule beats a 20% variant rule, because the customer saves more.
 * Target specificity is consulted *only* when two candidates produce the exact
 * same rial reduction; then the price is identical either way and all that matters
 * is that the same rule wins every time, so the snapshot stored on an order is
 * reproducible.
 *
 * Full ordering, applied in sequence until one candidate is left:
 *   1. largest actual rial reduction
 *   2. most specific matched target  (variant > product > category > brand)
 *   3. highest explicit priority
 *   4. lowest discount id
 */
class AutomaticDiscountResolver
{
    public function __construct(
        private readonly DiscountCalculator $calculator,
    ) {}

    /**
     * Choose the winner for one variant from its pre-filtered candidate set.
     *
     * @param  array<int, array{discount: Discount, targetType: DiscountTargetType}>  $candidates
     *                                                                                             Each entry is a matching rule together with the
     *                                                                                             most specific target type through which it matched.
     */
    public function resolve(AutomaticDiscountContextDTO $context, array $candidates): ?AutomaticDiscountResultDTO
    {
        $best = null;
        $bestReduction = 0;

        foreach ($candidates as $candidate) {
            $discount = $candidate['discount'];
            $reduction = $this->calculator->reductionFor($discount, $context->basePrice);

            // A rule that saves nothing (0%, a cap of 0, or a zero-priced variant)
            // is not a discount — showing a badge for it would be a lie.
            if ($reduction <= 0) {
                continue;
            }

            if ($best === null || $this->beats($reduction, $candidate, $bestReduction, $best)) {
                $best = $candidate;
                $bestReduction = $reduction;
            }
        }

        if ($best === null) {
            return null;
        }

        $discount = $best['discount'];

        return new AutomaticDiscountResultDTO(
            variantId: $context->variantId,
            discountId: $discount->id,
            discountName: $discount->name,
            discountType: $discount->discount_type,
            percentageBps: $discount->percentage_bps,
            fixedAmount: $discount->fixed_amount,
            discountAmount: $bestReduction,
            basePrice: $context->basePrice,
            effectivePrice: max(0, $context->basePrice - $bestReduction),
            matchedTargetType: $best['targetType'],
        );
    }

    /**
     * Strict "should the challenger replace the incumbent?" test.
     *
     * @param  array{discount: Discount, targetType: DiscountTargetType}  $challenger
     * @param  array{discount: Discount, targetType: DiscountTargetType}  $incumbent
     */
    private function beats(int $challengerReduction, array $challenger, int $incumbentReduction, array $incumbent): bool
    {
        // 1. Customer savings first, always.
        if ($challengerReduction !== $incumbentReduction) {
            return $challengerReduction > $incumbentReduction;
        }

        // 2. Identical savings — fall back to specificity (lower rank == more specific).
        $challengerRank = $challenger['targetType']->specificityRank();
        $incumbentRank = $incumbent['targetType']->specificityRank();

        if ($challengerRank !== $incumbentRank) {
            return $challengerRank < $incumbentRank;
        }

        // 3. Operator's explicit ordering.
        $challengerPriority = (int) $challenger['discount']->priority;
        $incumbentPriority = (int) $incumbent['discount']->priority;

        if ($challengerPriority !== $incumbentPriority) {
            return $challengerPriority > $incumbentPriority;
        }

        // 4. Last resort: the oldest rule wins, so the outcome is stable forever.
        return $challenger['discount']->id < $incumbent['discount']->id;
    }
}
