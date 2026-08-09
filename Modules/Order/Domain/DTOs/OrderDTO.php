<?php

declare(strict_types=1);

namespace Modules\Order\Domain\DTOs;

use Carbon\Carbon;
use Modules\Order\Domain\Enums\OrderStatus;
use Modules\Order\Domain\Models\Order;

class OrderDTO
{
    public function __construct(
        public readonly int $id,
        /** Customer-facing code (`bdo-XXXXXX`); the integer id is unchanged. */
        public readonly ?string $publicCode,
        public readonly int $userId,
        public readonly OrderStatus $status,
        public readonly int $totalAmount,
        public readonly int $shippingCost,
        public readonly int $taxAmount,
        /** Normalized coupon code frozen onto this order, or null. */
        public readonly ?string $couponCode,
        /** Order-level coupon reduction; never allocated across items. */
        public readonly int $couponDiscountAmount,
        /** Immutable coupon record, readable without the live coupon row. */
        public readonly ?array $couponSnapshot,
        /** Set by the first payment attempt; after this the coupon decision is locked. */
        public readonly ?Carbon $paymentPricingFinalizedAt,
        public readonly ?int $shipmentMethodId,
        public readonly ?string $shipmentMethodCode,
        public readonly array $shippingAddress,
        public readonly ?array $shipmentSnapshot,
        public readonly ?array $customerSnapshot,
        public readonly ?string $transactionRef,
        public readonly ?string $notes,
        public readonly Carbon $createdAt,
        public readonly array $items,
    ) {}

    /** @param OrderItemDTO[] $items */
    public static function fromModel(Order $order, array $items = []): self
    {
        return new self(
            id: $order->id,
            publicCode: $order->public_code,
            userId: $order->user_id,
            status: OrderStatus::from($order->status),
            totalAmount: $order->total_amount,
            shippingCost: $order->shipping_cost,
            taxAmount: $order->tax_amount,
            couponCode: $order->coupon_code,
            couponDiscountAmount: (int) $order->coupon_discount_amount,
            couponSnapshot: $order->coupon_snapshot,
            paymentPricingFinalizedAt: $order->payment_pricing_finalized_at,
            shipmentMethodId: $order->shipment_method_id,
            shipmentMethodCode: $order->shipment_method_code,
            shippingAddress: $order->shipping_address ?? [],
            shipmentSnapshot: $order->shipment_snapshot,
            customerSnapshot: $order->customer_snapshot,
            transactionRef: $order->transaction_ref,
            notes: $order->notes,
            createdAt: Carbon::parse($order->created_at),
            items: $items,
        );
    }
}
