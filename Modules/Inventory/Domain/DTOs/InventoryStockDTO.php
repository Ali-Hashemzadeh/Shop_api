<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\DTOs;

use Modules\Inventory\Domain\Models\InventoryStock;

class InventoryStockDTO
{
    public function __construct(
        public readonly string $sku,
        public readonly int $availableQuantity,
        public readonly int $physicalQuantity,
        public readonly int $reservedQuantity,
    ) {}

    public static function fromModel(InventoryStock $stock): self
    {
        return new self(
            sku: $stock->sku,
            availableQuantity: $stock->quantity - $stock->reserved_quantity,
            physicalQuantity: $stock->quantity,
            reservedQuantity: $stock->reserved_quantity,
        );
    }
}
