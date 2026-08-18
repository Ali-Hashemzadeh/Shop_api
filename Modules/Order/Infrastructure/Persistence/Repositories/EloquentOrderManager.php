<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Persistence\Repositories;

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Order\Application\Actions\CreateOrderAction;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\DTOs\AdminOrderDetailDTO;
use Modules\Order\Domain\DTOs\OrderDTO;
use Modules\Order\Domain\DTOs\OrderItemDTO;
use Modules\Order\Domain\DTOs\OrderPaidItemDTO;
use Modules\Order\Domain\Enums\OrderStatus;
use Modules\Order\Domain\Events\OrderPaidEvent;
use Modules\Order\Domain\Models\Order;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Domain\Exceptions\CouponRejectedException;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Domain\DTOs\ShipmentSelectionDTO;

class EloquentOrderManager implements OrderManagerInterface
{
    public function __construct(
        private readonly InventoryManagerInterface $inventory,
        // Order → Promotion, never the reverse: Promotion is handed primitives and
        // returns a quote, and it never calls back into Order.
        private readonly PromotionManagerInterface $promotion,
    ) {}

    public function createOrderFromCart(int $userId, ShipmentSelectionDTO $selection, ?string $notes = null): OrderDTO
    {
        return app(CreateOrderAction::class)->handle($userId, $selection, $notes);
    }

    public function finalizeForPayment(int $orderId, int $userId, ?string $couponCode = null): OrderDTO
    {
        return DB::transaction(function () use ($orderId, $userId, $couponCode): OrderDTO {
            /** @var Order $order */
            $order = Order::with('items')->lockForUpdate()->findOrFail($orderId);

            if ($order->user_id !== $userId) {
                abort(403, 'This order does not belong to you.');
            }

            if ($order->status !== OrderStatus::PENDING->value) {
                abort(422, 'Only pending orders can be paid.');
            }

            $submitted = $couponCode === null || trim($couponCode) === ''
                ? null
                : $this->promotion->normalizeCouponCode($couponCode);

            // ── Already frozen: every later attempt must charge the same amount ──
            if ($order->payment_pricing_finalized_at !== null) {
                // Repeating the frozen code, or omitting it, is fine — that is the
                // normal retry. Naming a different one is not, because the amount is
                // already committed (and possibly already sent to a gateway).
                if ($submitted !== null && $submitted !== $order->coupon_code) {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['The coupon for this order can no longer be changed.'],
                    ]);
                }

                return $this->toDTO($order->fresh('items'));
            }

            // ── First finalization ────────────────────────────────────────────
            if ($submitted === null) {
                // Freezing the *absence* of a coupon matters just as much: it stops a
                // code being bolted on after the customer has seen a payment page.
                $order->update(['payment_pricing_finalized_at' => now()]);

                return $this->toDTO($order->fresh('items'));
            }

            $merchandiseSubtotal = $order->merchandiseSubtotal();

            try {
                $quote = $this->promotion->reserveCouponForOrder(
                    code: $submitted,
                    orderId: $order->id,
                    userId: $userId,
                    merchandiseSubtotal: $merchandiseSubtotal,
                );
            } catch (CouponRejectedException $e) {
                throw ValidationException::withMessages([
                    'coupon_code' => [$e->getMessage()],
                ]);
            }

            $total = $merchandiseSubtotal - $quote->discountAmount + $order->shipping_cost + $order->tax_amount;

            if ($total <= 0) {
                // Free orders are out of scope: there is no zero-value payment flow to
                // hand this to. Throwing here rolls back the enclosing transaction,
                // which also undoes the reservation just taken — so the coupon is not
                // silently consumed by a rejected checkout.
                throw ValidationException::withMessages([
                    'coupon_code' => ['This coupon cannot be applied to this order.'],
                ]);
            }

            $order->update([
                'coupon_code' => $quote->code,
                'coupon_discount_amount' => $quote->discountAmount,
                'coupon_snapshot' => $quote->toSnapshot(),
                'total_amount' => $total,
                'payment_pricing_finalized_at' => now(),
            ]);

            return $this->toDTO($order->fresh('items'));
        });
    }

    /**
     * Shared "mark order paid" application path. Idempotent: on the first
     * transition it commits the inventory reservation exactly once, activates
     * the operational shipment record, and redeems any reserved coupon; repeat
     * calls are no-ops.
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

            // Promote the coupon reservation to a redemption. Placed on this shared
            // path so an online capture and an in-person cash payment redeem
            // identically. Idempotent, and a no-op when the order had no coupon.
            $this->promotion->redeemCouponForOrder($order->id);

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
            $paidItems = [];
            $totalItemDiscount = 0;
            foreach ($order->items as $item) {
                $itemDiscount = (int) ($item->automatic_discount_amount_per_unit ?? 0) * (int) $item->quantity;
                $totalItemDiscount += $itemDiscount;
                $snapshot = $item->product_snapshot ?? [];
                $autoSnapshot = $item->automatic_discount_snapshot ?? [];

                $paidItems[] = new OrderPaidItemDTO(
                    productId: (int) ($snapshot['product_id'] ?? 0),
                    variantId: (int) ($snapshot['variant_id'] ?? 0),
                    quantity: (int) $item->quantity,
                    unitPrice: (int) $item->price_per_unit,
                    discountAmount: $itemDiscount,
                    categoryIds: array_values(array_map('intval', $snapshot['category_ids'] ?? [])),
                    discountId: isset($autoSnapshot['discount_id']) ? (int) $autoSnapshot['discount_id'] : null,
                    regularUnitPrice: (int) ($item->regular_price_per_unit ?: $item->price_per_unit),
                    sku: $item->sku,
                    productTitle: $item->product_title,
                );
            }

            $couponAmount = (int) ($order->coupon_discount_amount ?? 0);
            $couponSnapshot = $order->coupon_snapshot ?? [];
            $couponId = isset($couponSnapshot['coupon_id']) ? (int) $couponSnapshot['coupon_id'] : null;

            Event::dispatch(new OrderPaidEvent(
                orderId: $order->id,
                userId: $order->user_id,
                totalAmount: (int) $order->total_amount,
                orderPublicCode: $order->public_code,
                discountAmount: $totalItemDiscount,
                couponAmount: $couponAmount,
                couponId: $couponId,
                items: $paidItems,
                paidAt: now()->toDateTimeString(),
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

    public function getUserOrders(int $userId, int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = Order::with('items')
            ->where('user_id', $userId)
            ->orderByDesc('created_at');

        $search = $search === null ? '' : trim($search);

        if ($search !== '') {
            // The ownership constraint above is applied unconditionally and is
            // never relaxed by a search term, so another customer's code simply
            // yields an empty page — indistinguishable from a code that does not
            // exist. The search therefore cannot be used to probe for the
            // existence of other people's orders.
            //
            // Exact equality on the unique index only: a partial code must never
            // match, or orders could be enumerated by prefix.
            $query->where(
                'public_code',
                PublicCodeGenerator::matches($search, PublicCodeEntity::Order)
                    ? PublicCodeGenerator::normalize($search)
                    : $search,
            );
        }

        return $query->paginate(min(max($perPage, 1), 100))
            ->through(fn (Order $order) => $this->toDTO($order));
    }

    public function findOrder(int $orderId): ?OrderDTO
    {
        $order = Order::with('items')->find($orderId);

        return $order ? $this->toDTO($order) : null;
    }

    public function findUserOrderByPublicCode(int $userId, string $publicCode): ?OrderDTO
    {
        $order = Order::with('items')
            ->where('user_id', $userId)
            ->where('public_code', PublicCodeGenerator::normalize($publicCode))
            ->first();

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
