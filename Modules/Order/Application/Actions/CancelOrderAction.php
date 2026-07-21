<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Order\Domain\DTOs\OrderDTO;
use Modules\Order\Domain\DTOs\OrderItemDTO;
use Modules\Order\Domain\Enums\OrderStatus;
use Modules\Order\Domain\Events\OrderCancelledEvent;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;

class CancelOrderAction
{
    public function __construct(
        private readonly InventoryManagerInterface $inventory,
        private readonly ShipmentManagerInterface $shipment,
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

            // Explicit, customer-initiated cancellation — announce it. Dispatched
            // here rather than inside releaseAndCancel because that primitive is
            // also used to retire a superseded pending order during checkout,
            // which the customer never asked for and must not be notified about.
            Event::dispatch(new OrderCancelledEvent(
                orderId: $order->id,
                userId: $order->user_id,
            ));

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

        // Release any held/confirmed local-delivery slot for this order (no-op
        // for postal/pickup, and idempotent when repeated).
        $this->shipment->releasePendingOrder($order->id);

        $order->update(['status' => OrderStatus::CANCELLED->value]);
    }
}
