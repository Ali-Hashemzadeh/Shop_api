<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Order\Application\Actions\CreateOrderAction;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\DTOs\AdminOrderDetailDTO;
use Modules\Order\Domain\DTOs\OrderDTO;
use Modules\Order\Domain\DTOs\OrderItemDTO;
use Modules\Order\Domain\Enums\OrderStatus;
use Modules\Order\Domain\Events\OrderPaidEvent;
use Modules\Order\Domain\Models\Order;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Domain\DTOs\ShipmentSelectionDTO;

class EloquentOrderManager implements OrderManagerInterface
{
    public function __construct(
        private readonly InventoryManagerInterface $inventory,
    ) {}

    public function createOrderFromCart(int $userId, ShipmentSelectionDTO $selection, ?string $notes = null): OrderDTO
    {
        return app(CreateOrderAction::class)->handle($userId, $selection, $notes);
    }

    /**
     * Shared "mark order paid" application path. Idempotent: on the first
     * transition it commits the inventory reservation exactly once and activates
     * the operational shipment record; repeat calls are no-ops.
     */
    public function markAsPaid(int $orderId, string $transactionRef): OrderDTO
    {
        return DB::transaction(function () use ($orderId, $transactionRef): OrderDTO {
            /** @var Order $order */
            $order = Order::with('items')->lockForUpdate()->findOrFail($orderId);

            // Already realized — do not re-commit inventory or re-activate shipment.
            if (in_array($order->status, OrderStatus::soldStatuses(), true) || $order->status === OrderStatus::COMPLETED->value) {
                return $this->toDTO($order->fresh('items'));
            }

            $order->update([
                'status' => OrderStatus::PAID->value,
                'transaction_ref' => $transactionRef,
            ]);

            foreach ($order->items as $item) {
                $this->inventory->commitReservation($item->sku, $item->quantity, $order->id);
            }

            // Activate the operational shipment record (idempotent by order_id).
            app(ShipmentManagerInterface::class)->activateForPaidOrder(
                orderId: $order->id,
                userId: $order->user_id,
                shipmentSnapshot: $order->shipment_snapshot ?? [],
            );

            // Announce the real transition only — the early return above means a
            // repeated callback never reaches this line, so notifications are not
            // duplicated. Listeners implement ShouldHandleEventsAfterCommit, so
            // nothing is sent if this (or an enclosing) transaction rolls back.
            Event::dispatch(new OrderPaidEvent(
                orderId: $order->id,
                userId: $order->user_id,
                totalAmount: (int) $order->total_amount,
            ));

            return $this->toDTO($order->fresh('items'));
        });
    }

    public function markAsComplete(int $orderId): OrderDTO
    {
        $order = Order::with('items')->findOrFail($orderId);
        $order->update(['status' => OrderStatus::PROCESSING->value]);

        return $this->toDTO($order);
    }

    public function syncStatusFromShipment(int $orderId, string $orderStatus): void
    {
        Order::where('id', $orderId)->update(['status' => $orderStatus]);
    }

    public function getUserOrders(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return Order::with('items')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (Order $order) => $this->toDTO($order));
    }

    public function findOrder(int $orderId): ?OrderDTO
    {
        $order = Order::with('items')->find($orderId);

        return $order ? $this->toDTO($order) : null;
    }

    public function getAdminOrders(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Order::with('items')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['order_id'])) {
            $query->where('id', (int) $filters['order_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate(min(max($perPage, 1), 100))
            ->through(fn (Order $order) => $this->toDTO($order));
    }

    public function getAdminOrderDetail(int $orderId): ?AdminOrderDetailDTO
    {
        $order = Order::with('items')->find($orderId);

        if ($order === null) {
            return null;
        }

        // Current fulfillment state comes exclusively through the Shipment contract —
        // never a Shipment model. Null until the order is paid and a shipment activates.
        $shipment = app(ShipmentManagerInterface::class)->findForOrder($orderId);

        return new AdminOrderDetailDTO($this->toDTO($order), $shipment);
    }

    private function toDTO(Order $order): OrderDTO
    {
        $items = $order->items->map(fn ($item) => OrderItemDTO::fromModel($item))->all();

        return OrderDTO::fromModel($order, $items);
    }
}
