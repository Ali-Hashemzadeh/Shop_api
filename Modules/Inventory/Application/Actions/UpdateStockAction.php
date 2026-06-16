<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Actions;

use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Inventory\Domain\DTOs\InventoryStockDTO;

class UpdateStockAction
{
    public function __construct(
        private readonly InventoryManagerInterface $inventory,
    ) {}

    public function handle(
        string $sku,
        int $quantityChange,
        string $type,
        ?string $notes = null,
    ): InventoryStockDTO {
        return $this->inventory->adjustStock($sku, $quantityChange, $type, null, null, $notes);
    }
}
