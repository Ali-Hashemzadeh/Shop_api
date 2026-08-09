<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\DTOs;

use Carbon\Carbon;
use Modules\Promotion\Domain\Models\Coupon;

/** Admin-facing view of a coupon code, including live usage counters. */
class CouponDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $discountId,
        public readonly string $code,
        public readonly bool $isActive,
        public readonly ?int $usageLimit,
        public readonly ?int $usageLimitPerUser,
        /** reserved + redeemed; released claims never count. */
        public readonly ?int $usedCount = null,
        public readonly ?DiscountDTO $discount = null,
        public readonly ?Carbon $createdAt = null,
    ) {}

    public static function fromModel(Coupon $coupon, ?int $usedCount = null, ?DiscountDTO $discount = null): self
    {
        return new self(
            id: $coupon->id,
            discountId: $coupon->discount_id,
            code: $coupon->code,
            isActive: $coupon->is_active,
            usageLimit: $coupon->usage_limit,
            usageLimitPerUser: $coupon->usage_limit_per_user,
            usedCount: $usedCount,
            discount: $discount,
            createdAt: $coupon->created_at,
        );
    }
}
