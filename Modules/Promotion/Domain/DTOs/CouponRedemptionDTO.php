<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\DTOs;

use Carbon\Carbon;
use Modules\Promotion\Domain\Enums\RedemptionStatus;
use Modules\Promotion\Domain\Models\CouponRedemption;

/** Admin-facing view of one order's claim on a coupon. */
class CouponRedemptionDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $couponId,
        public readonly ?string $couponCode,
        public readonly int $orderId,
        public readonly int $userId,
        public readonly RedemptionStatus $status,
        public readonly int $discountAmount,
        public readonly ?Carbon $createdAt = null,
        public readonly ?Carbon $updatedAt = null,
    ) {}

    public static function fromModel(CouponRedemption $redemption, ?string $couponCode = null): self
    {
        return new self(
            id: $redemption->id,
            couponId: $redemption->coupon_id,
            couponCode: $couponCode,
            orderId: $redemption->order_id,
            userId: $redemption->user_id,
            status: $redemption->status,
            discountAmount: $redemption->discount_amount,
            createdAt: $redemption->created_at,
            updatedAt: $redemption->updated_at,
        );
    }
}
