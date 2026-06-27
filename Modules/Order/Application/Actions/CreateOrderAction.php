<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Order\Domain\DTOs\OrderDTO;
use Modules\Order\Domain\DTOs\OrderItemDTO;
use Modules\Order\Domain\Exceptions\EmptyCartException;
use Modules\Order\Domain\Exceptions\InvalidAddressException;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;

class CreateOrderAction
{
    public function __construct(
        private readonly CartManagerInterface $cart,
        private readonly InventoryManagerInterface $inventory,
    ) {}

    public function handle(int $userId, int $addressId, int $shipmentMethodId, ?string $notes = null): OrderDTO
    {
        $cartDto = $this->cart->findOrCreateCart($userId, null);
        $enrichedCart = $this->cart->getCart($cartDto->id);

        if (empty($enrichedCart->items)) {
            throw new EmptyCartException;
        }

        $address = DB::table('addresses')->find($addressId);

        if (! $address || $address->user_id !== $userId) {
            throw new InvalidAddressException;
        }

        $addressSnapshot = [
            'id' => $address->id,
            'title' => $address->title,
            'province_id' => $address->province_id,
            'city_id' => $address->city_id,
            'postal_code' => $address->postal_code,
            'address' => $address->address,
        ];

        return DB::transaction(function () use ($userId, $enrichedCart, $addressSnapshot, $shipmentMethodId, $notes) {
            $pending = Order::with('items')
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->first();

            if ($pending) {
                foreach ($pending->items as $item) {
                    $this->inventory->releaseReservation($item->sku, $item->quantity, $pending->id);
                }
                $pending->update(['status' => 'cancelled']);
            }

            $order = Order::create([
                'user_id' => $userId,
                'status' => 'pending',
                'total_amount' => $enrichedCart->totalPrice,
                'shipping_cost' => 0,
                'tax_amount' => 0,
                'shipment_method_id' => $shipmentMethodId,
                'shipping_address' => $addressSnapshot,
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

            $this->cart->clearCart($enrichedCart->id);

            return OrderDTO::fromModel($order, $itemDTOs);
        });
    }
}
