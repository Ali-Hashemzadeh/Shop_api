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

        // Authoritative pricing comes from the variants just re-read from Catalog —
        // which already carry live automatic-discount pricing — not from whatever the
        // cart displayed earlier. A discount that ended while the customer was on the
        // checkout page therefore does not carry into the order.
        $subtotal = 0;
        foreach ($enrichedCart->items as $cartItem) {
            $variant = $variantsBySku[$cartItem->sku];
            $subtotal += $variant->effectivePrice() * $cartItem->quantity;
        }

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

            // createWithPublicCode: the `bdo-` code is assigned by the model hook
            // before the insert, and retried here if the unique index rejects a
            // concurrent duplicate.
            $order = Order::createWithPublicCode([
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
                $variant = $variantsBySku[$cartItem->sku];
                $automaticDiscount = $variant->automaticDiscount;
                $effectivePrice = $variant->effectivePrice();

                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'sku' => $cartItem->sku,
                    'product_title' => $cartItem->productName ?? '',
                    'variant_attributes' => $cartItem->attributes,
                    'product_snapshot' => [
                        'title' => $cartItem->productName,
                        'sku' => $cartItem->sku,
                        'image_url' => $cartItem->imageUrl,
                        'primary_image_url' => $cartItem->primaryImageUrl,
                        'attributes' => $cartItem->attributes,
                        'product_id' => $variant->productId,
                        'variant_id' => $variant->id,
                        'category_ids' => $variant->categoryIds,
                    ],
                    'quantity' => $cartItem->quantity,
                    'max_quantity_per_order_snapshot' => $variant->maxQuantityPerOrder,
                    // The strike-through price at checkout.
                    'regular_price_per_unit' => $variant->basePrice,
                    'automatic_discount_amount_per_unit' => $automaticDiscount?->discountAmount ?? 0,
                    // Self-contained record of the winning rule, so this line stays
                    // explainable even if the discount is later edited or deleted.
                    'automatic_discount_snapshot' => $automaticDiscount?->toSnapshot(),
                    // What is actually charged per unit, before any order-level coupon.
                    'price_per_unit' => $effectivePrice,
                    // Legacy Catalog column, deliberately null on every new order.
                    'compare_at_price' => null,
                    'line_total' => $effectivePrice * $cartItem->quantity,
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
