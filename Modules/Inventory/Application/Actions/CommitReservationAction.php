<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Actions;

use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;

class CommitReservationAction
{
    public function __construct(
        private readonly InventoryManagerInterface $inventory,
    ) {}

    public function handle(string $sku, int $quantity, int $orderId): bool
    {
        return $this->inventory->commitReservation($sku, $quantity, $orderId);
    }
}
