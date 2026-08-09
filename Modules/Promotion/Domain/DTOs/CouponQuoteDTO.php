<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\DTOs;

use Modules\Promotion\Domain\Enums\DiscountType;

/**
 * What one coupon would take off a given merchandise subtotal.
 *
 * Purely an arithmetic answer: Promotion is told the frozen post-automatic
 * subtotal and returns a rial reduction. It deliberately does not know about
 * shipping, tax, or the order total — deciding what this amount does to an order
 * is Order's job, which is what keeps Promotion free of any Order dependency.
 */
class CouponQuoteDTO
{
    public function __construct(
        public readonly int $couponId,
        /** Normalized (trimmed + uppercased) code, safe to persist on the order. */
        public readonly string $code,
        public readonly int $discountId,
        public readonly string $discountName,
        public readonly DiscountType $discountType,
        public readonly ?int $percentageBps,
        public readonly ?int $fixedAmount,
        public readonly ?int $maxDiscountAmount,
        /** Actual rial reduction, already capped and clamped to the subtotal. */
        public readonly int $discountAmount,
        public readonly int $merchandiseSubtotal,
    ) {}

    /**
     * The immutable payload stored on orders.coupon_snapshot.
     *
     * A historical order must render its coupon line without consulting the live
     * coupon or discount row, both of which may since have been edited, deactivated,
     * or soft-deleted.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'coupon_id' => $this->couponId,
            'code' => $this->code,
            'discount_id' => $this->discountId,
            'discount_name' => $this->discountName,
            'discount_type' => $this->discountType->value,
            'percentage_bps' => $this->percentageBps,
            'fixed_amount' => $this->fixedAmount,
            'max_discount_amount' => $this->maxDiscountAmount,
            'discount_amount' => $this->discountAmount,
            'merchandise_subtotal' => $this->merchandiseSubtotal,
        ];
    }
}
