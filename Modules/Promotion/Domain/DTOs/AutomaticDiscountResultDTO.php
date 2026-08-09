<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\DTOs;

use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Enums\DiscountType;

/**
 * The one automatic discount that won for a variant, plus the arithmetic it produced.
 *
 * Only ever describes a single rule: automatic discounts never stack, so there is
 * no list here by construction. Carries enough detail for Catalog to render a badge
 * and for Order to freeze a permanent historical snapshot.
 */
class AutomaticDiscountResultDTO
{
    public function __construct(
        public readonly int $variantId,
        public readonly int $discountId,
        public readonly string $discountName,
        public readonly DiscountType $discountType,
        /** Basis points for percentage rules, null for fixed-amount rules. */
        public readonly ?int $percentageBps,
        /** Configured rial amount for fixed rules, null for percentage rules. */
        public readonly ?int $fixedAmount,
        /** The actual rial reduction after caps — this is what the comparison used. */
        public readonly int $discountAmount,
        public readonly int $basePrice,
        public readonly int $effectivePrice,
        public readonly DiscountTargetType $matchedTargetType,
    ) {}

    /**
     * The immutable payload stored on order_items.automatic_discount_snapshot.
     *
     * Deliberately self-contained: a historical order must stay explainable after
     * the discount row is edited, deactivated, or soft-deleted, so nothing here may
     * require a live lookup to interpret.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'discount_id' => $this->discountId,
            'name' => $this->discountName,
            'type' => $this->discountType->value,
            'percentage_bps' => $this->percentageBps,
            'fixed_amount' => $this->fixedAmount,
            'matched_target_type' => $this->matchedTargetType->value,
            'base_price' => $this->basePrice,
            'discount_amount' => $this->discountAmount,
            'effective_price' => $this->effectivePrice,
        ];
    }
}
