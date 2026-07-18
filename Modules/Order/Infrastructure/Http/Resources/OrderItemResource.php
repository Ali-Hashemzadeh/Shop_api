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
            'price_per_unit' => $dto->pricePerUnit,
            'line_total' => $dto->lineTotal,
        ];
    }
}
