<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
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
        private readonly CatalogManagerInterface $catalog,
        private readonly InventoryManagerInterface $inventory,
        private readonly CancelOrderAction $cancelOrder,
        private readonly ShipmentManagerInterface $shipment,
        private readonly IdentityManagerInterface $identity,
    ) {}

    public function handle(int $userId, ShipmentSelectionDTO $selection, ?string $notes = null): OrderDTO
    {
        $cartDto = $this->cart->findOrCreateCart($userId, null);
        $enrichedCart = $this->cart->getCart($cartDto->id);

        if (empty($enrichedCart->items)) {
            throw new EmptyCartException;
        }

        $quantitiesBySku = collect($enrichedCart->items)
            ->groupBy(fn ($item) => $item->sku)
            ->map(fn ($items): int => $items->sum('quantity'))
            ->all();
        $variantsBySku = $this->catalog->getVariantsBySkus(array_keys($quantitiesBySku));
        $errors = [];

        foreach ($quantitiesBySku as $sku => $quantity) {
            $variant = $variantsBySku[$sku] ?? null;

            if ($variant === null) {
                $errors["items.{$sku}.quantity"] = ['This item is no longer available.'];

                continue;
            }

            if ($variant->maxQuantityPerOrder !== null && $quantity > $variant->maxQuantityPerOrder) {
                $errors["items.{$sku}.quantity"] = [
                    "This item is limited to {$variant->maxQuantityPerOrder} units per order. Your cart currently contains {$quantity}.",
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $subtotal = $enrichedCart->totalPrice;
        $shippingCost = $selection->shippingCost;
        $snapshot = $selection->toSnapshot();
        $expiresAt = now()->addMinutes((int) config('shipment.pending_order_ttl_minutes', 15));

        $customer = $this->identity->getUserSummary($userId);
        $customerSnapshot = [
            'name' => $customer->name,
            'last_name' => $customer->lastName,
            'phone' => $customer->phone,
            'email' => $customer->email,
        ];

        return DB::transaction(function () use ($userId, $enrichedCart, $variantsBySku, $selection, $snapshot, $customerSnapshot, $subtotal, $shippingCost, $notes, $expiresAt) {
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
                'customer_snapshot' => $customerSnapshot,
                'notes' => $notes,
            ]);

            $itemDTOs = [];
            foreach ($enrichedCart->items as $cartItem) {
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'sku' => $cartItem->sku,
                    'product_title' => $cartItem->productName ?? '',
                    'variant_attributes' => $cartItem->attributes,
                    'product_snapshot' => [
                        'title' => $cartItem->productName,
                        'sku' => $cartItem->sku,
                        'image_url' => $cartItem->imageUrl,
                        'attributes' => $cartItem->attributes,
                    ],
                    'quantity' => $cartItem->quantity,
                    'max_quantity_per_order_snapshot' => $variantsBySku[$cartItem->sku]->maxQuantityPerOrder,
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
