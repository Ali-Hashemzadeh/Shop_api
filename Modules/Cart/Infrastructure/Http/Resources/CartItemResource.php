<?php

declare(strict_types=1);

namespace Modules\Cart\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'available_stock' => $this->availableStock,
            'max_quantity_per_order' => $this->maxQuantityPerOrder,
            'effective_max_quantity' => $this->effectiveMaxQuantity,
            'remaining_addable_quantity' => $this->remainingAddableQuantity,
            'quantity_valid' => $this->quantityValid,
            'product_name' => $this->productName,
            'type' => $this->type,
            'attributes' => $this->attributes,
            // Regular price, and what this unit actually costs after the winning
            // automatic discount. Both are recomputed live on every cart read.
            'base_price' => $this->basePrice,
            'effective_price' => $this->effectivePrice,
            'automatic_discount' => $this->automaticDiscount,
            'image_url' => $this->imageUrl,
            'primary_image_url' => $this->primaryImageUrl,
            // line_total is the payable amount (effective_price × quantity);
            // regular_line_total is the strike-through figure.
            'line_total' => $this->lineTotal,
            'regular_line_total' => $this->regularLineTotal,
            'automatic_discount_amount' => $this->automaticDiscountAmount,
        ];
    }
}
