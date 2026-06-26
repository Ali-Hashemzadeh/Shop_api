<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Order\Domain\Models\Order;

class CancelExpiredOrdersAction
{
    public function __construct(
        private readonly InventoryManagerInterface $inventory,
    ) {}

    public function handle(): int
    {
        $cutoff = now()->subMinutes(15);
        $count = 0;

        Order::with('items')
            ->where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->each(function (Order $order) use (&$count) {
                foreach ($order->items as $item) {
                    $this->inventory->releaseReservation($item->sku, $item->quantity, $order->id);
                }
                $order->update(['status' => 'cancelled']);
                $count++;
            });

        return $count;
    }
}
