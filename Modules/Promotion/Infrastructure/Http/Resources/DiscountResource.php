<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Promotion\Domain\DTOs\DiscountDTO;

/** @mixin DiscountDTO */
class DiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DiscountDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'name' => $dto->name,
            'description' => $dto->description,
            'trigger_type' => $dto->triggerType->value,
            'scope' => $dto->scope->value,
            'discount_type' => $dto->discountType->value,
            'percentage_bps' => $dto->percentageBps,
            'fixed_amount' => $dto->fixedAmount,
            'max_discount_amount' => $dto->maxDiscountAmount,
            'min_subtotal' => $dto->minSubtotal,
            'starts_at' => $dto->startsAt?->toISOString(),
            'ends_at' => $dto->endsAt?->toISOString(),
            'is_active' => $dto->isActive,
            'priority' => $dto->priority,
            'targets' => DiscountTargetResource::collection($dto->targets),
            'created_at' => $dto->createdAt?->toISOString(),
        ];
    }
}
