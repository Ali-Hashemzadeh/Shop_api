<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Order\Domain\DTOs\OrderDTO;
use Modules\Order\Domain\DTOs\OrderItemDTO;
use Modules\Order\Domain\Exceptions\EmptyCartException;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Domain\DTOs\ShipmentSelectionDTO;

class CreateOrderAction
{
    public function __construct(
        private readonly CartManagerInterface $cart,
        private readonly InventoryManagerInterface $inventory,
        private readonly CancelOrderAction $cancelOrder,
        private readonly ShipmentManagerInterface $shipment,
    ) {}

    public function handle(int $userId, ShipmentSelectionDTO $selection, ?string $notes = null): OrderDTO
    {
        $cartDto = $this->cart->findOrCreateCart($userId, null);
        $enrichedCart = $this->cart->getCart($cartDto->id);

        if (empty($enrichedCart->items)) {
            throw new EmptyCartException;
        }

        $subtotal = $enrichedCart->totalPrice;
        $shippingCost = $selection->shippingCost;
        $snapshot = $selection->toSnapshot();
        $expiresAt = now()->addMinutes((int) config('shipment.pending_order_ttl_minutes', 15));

        return DB::transaction(function () use ($userId, $enrichedCart, $selection, $snapshot, $subtotal, $shippingCost, $notes, $expiresAt) {
            $pending = Order::with('items')
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->first();

            if ($pending) {
                // Release the old order's inventory reservation and slot hold before
                // the new pending order takes its place.
                $this->cancelOrder->releaseAndCancel($pending);
            }

            $order = Order::create([
                'user_id' => $userId,
                'status' => 'pending',
                'total_amount' => $subtotal + $shippingCost,
                'shipping_cost' => $shippingCost,
                'tax_amount' => 0,
                'shipment_method_code' => $selection->methodCode,
                'shipping_address' => $selection->address ?? [],
                'shipment_snapshot' => $snapshot,
                'notes' => $notes,
            ]);

            $itemDTOs = [];
            foreach ($enrichedCart->items as $cartItem) {
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'sku' => $cartItem->sku,
                    'product_title' => $cartItem->productName ?? '',
                    'variant_attributes' => $cartItem->attributes,
                    'quantity' => $cartItem->quantity,
                    'price_per_unit' => $cartItem->basePrice ?? 0,
                    'line_total' => $cartItem->lineTotal,
                ]);
                $itemDTOs[] = OrderItemDTO::fromModel($orderItem);

                $this->inventory->reserveStock($cartItem->sku, $cartItem->quantity, $order->id);
            }

            // Lock + hold the local-delivery slot (no-op for postal/pickup).
            $this->shipment->holdForPendingOrder($order->id, $userId, $selection, $expiresAt);

            return OrderDTO::fromModel($order, $itemDTOs);
        });
    }
}
