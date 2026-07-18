<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Order\Domain\DTOs\OrderDTO;
use Modules\Order\Domain\DTOs\OrderItemDTO;
use Modules\Order\Domain\Enums\OrderStatus;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;

/**
 * Admin/operator order cancellation. Unlike the customer flow there is no ownership
 * check, but it deliberately reuses CancelOrderAction's release/cancel primitive so
 * the inventory-release + slot-release + status transition stay identical and are
 * never duplicated. Cancellation is restricted to pending orders: only there is stock
 * still reserved (not committed) and the delivery slot merely held — cancelling a paid
 * order would require refund + committed-stock return, which is a separate future flow.
 */
class AdminCancelOrderAction
{
    public function __construct(
        private readonly CancelOrderAction $cancelOrder,
    ) {}

    public function handle(int $orderId): OrderDTO
    {
        $order = Order::with('items')->find($orderId);

        if ($order === null) {
            abort(404, 'Order not found.');
        }

        if ($order->status !== OrderStatus::PENDING->value) {
            abort(422, 'Only pending orders can be cancelled.');
        }

        return DB::transaction(function () use ($order) {
            $this->cancelOrder->releaseAndCancel($order);

            $items = $order->items->map(fn (OrderItem $item) => OrderItemDTO::fromModel($item))->all();

            return OrderDTO::fromModel($order, $items);
        });
    }
}
