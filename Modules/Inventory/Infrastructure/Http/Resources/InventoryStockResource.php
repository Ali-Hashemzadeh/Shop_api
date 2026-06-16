<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sku' => $this->sku,
            'available_quantity' => $this->availableQuantity,
            'physical_quantity' => $this->physicalQuantity,
            'reserved_quantity' => $this->reservedQuantity,
        ];
    }
}
