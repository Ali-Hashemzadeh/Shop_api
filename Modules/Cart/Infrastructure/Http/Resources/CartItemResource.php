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
            'product_name' => $this->productName,
            'base_price' => $this->basePrice,
            'compare_at_price' => $this->compareAtPrice,
            'image_url' => $this->imageUrl,
            'line_total' => $this->lineTotal,
        ];
    }
}
