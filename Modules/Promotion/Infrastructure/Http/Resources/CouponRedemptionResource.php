<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Promotion\Domain\DTOs\CouponRedemptionDTO;

/** @mixin CouponRedemptionDTO */
class CouponRedemptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CouponRedemptionDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'coupon_id' => $dto->couponId,
            'coupon_code' => $dto->couponCode,
            'order_id' => $dto->orderId,
            'user_id' => $dto->userId,
            'status' => $dto->status->value,
            'discount_amount' => $dto->discountAmount,
            'created_at' => $dto->createdAt?->toISOString(),
            'updated_at' => $dto->updatedAt?->toISOString(),
        ];
    }
}
