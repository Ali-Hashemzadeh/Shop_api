<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Order\Domain\DTOs\OrderDTO;
use Modules\Order\Domain\DTOs\OrderItemDTO;
use Modules\Order\Domain\Enums\OrderStatus;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;

class CancelOrderAction
{
    public function __construct(
        private readonly InventoryManagerInterface $inventory,
    ) {}

    public function handle(int $orderId, int $userId): OrderDTO
    {
        $order = Order::with('items')->find($orderId);

        if ($order === null) {
            abort(404, 'Order not found.');
        }

        if ($order->user_id !== $userId) {
            abort(403, 'This order does not belong to you.');
        }

        if ($order->status !== OrderStatus::PENDING->value) {
            abort(422, 'Only pending orders can be cancelled.');
        }

        return DB::transaction(function () use ($order) {
            $this->releaseAndCancel($order);

            $items = $order->items->map(fn (OrderItem $item) => OrderItemDTO::fromModel($item))->all();

            return OrderDTO::fromModel($order, $items);
        });
    }

    /**
     * Release every reserved unit for the order back to available stock and
     * mark it cancelled. Callers must wrap this in a DB transaction.
     */
    public function releaseAndCancel(Order $order): void
    {
        foreach ($order->items as $item) {
            $this->inventory->releaseReservation($item->sku, $item->quantity, $order->id);
        }

        $order->update(['status' => OrderStatus::CANCELLED->value]);
    }
}
