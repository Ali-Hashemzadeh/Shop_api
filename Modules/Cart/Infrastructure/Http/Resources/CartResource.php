<?php

declare(strict_types=1);

namespace Modules\Cart\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'session_id' => $this->sessionId,
            'items' => CartItemResource::collection($this->items),
            'item_count' => $this->itemCount,
            'total_quantity' => $this->totalQuantity,
            'total_price' => $this->totalPrice,
        ];
    }
}
