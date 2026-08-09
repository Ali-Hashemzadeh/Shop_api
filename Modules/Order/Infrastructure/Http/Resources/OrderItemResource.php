<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Domain\DTOs\OrderItemDTO;

/** @mixin OrderItemDTO */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrderItemDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'sku' => $dto->sku,
            'product_title' => $dto->productTitle,
            'variant_attributes' => $dto->variantAttributes,
            'product_snapshot' => $dto->productSnapshot,
            'quantity' => $dto->quantity,
            'max_quantity_per_order_snapshot' => $dto->maxQuantityPerOrderSnapshot,
            // Frozen at checkout: the regular price, the automatic reduction that
            // won, and the price actually charged. Never recomputed from the live
            // Promotion rules, which may since have changed.
            'regular_price_per_unit' => $dto->regularPricePerUnit,
            'automatic_discount_amount_per_unit' => $dto->automaticDiscountAmountPerUnit,
            'automatic_discount' => $dto->automaticDiscountSnapshot,
            'price_per_unit' => $dto->pricePerUnit,
            // Legacy field, retained for historical orders only.
            'compare_at_price' => $dto->compareAtPrice,
            'line_total' => $dto->lineTotal,
        ];
    }
}
