<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Application\Actions\CreateOrderAction;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\DTOs\OrderDTO;
use Modules\Order\Domain\DTOs\OrderItemDTO;
use Modules\Order\Domain\Models\Order;

class EloquentOrderManager implements OrderManagerInterface
{
    public function createOrderFromCart(int $userId, int $addressId, int $shipmentMethodId, ?string $notes = null): OrderDTO
    {
        return app(CreateOrderAction::class)->handle($userId, $addressId, $shipmentMethodId, $notes);
    }

    public function markAsPaid(int $orderId, string $transactionRef): OrderDTO
    {
        $order = Order::with('items')->findOrFail($orderId);
        $order->update([
            'status' => 'paid',
            'transaction_ref' => $transactionRef,
        ]);

        return $this->toDTO($order);
    }

    public function markAsComplete(int $orderId): OrderDTO
    {
        $order = Order::with('items')->findOrFail($orderId);
        $order->update(['status' => 'processing']);

        return $this->toDTO($order);
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

    private function toDTO(Order $order): OrderDTO
    {
        $items = $order->items->map(fn ($item) => OrderItemDTO::fromModel($item))->all();

        return OrderDTO::fromModel($order, $items);
    }
}
