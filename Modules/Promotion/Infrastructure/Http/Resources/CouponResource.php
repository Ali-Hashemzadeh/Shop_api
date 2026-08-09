<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Promotion\Domain\DTOs\CouponDTO;

/** @mixin CouponDTO */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CouponDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'code' => $dto->code,
            'discount_id' => $dto->discountId,
            'is_active' => $dto->isActive,
            'usage_limit' => $dto->usageLimit,
            'usage_limit_per_user' => $dto->usageLimitPerUser,
            // reserved + redeemed; released claims are back in the pool.
            'used_count' => $dto->usedCount,
            'remaining_uses' => $dto->usageLimit === null
                ? null
                : max(0, $dto->usageLimit - (int) $dto->usedCount),
            'discount' => $dto->discount === null ? null : new DiscountResource($dto->discount),
            'created_at' => $dto->createdAt?->toISOString(),
        ];
    }
}
