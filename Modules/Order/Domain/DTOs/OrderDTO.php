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
        public readonly int $userId,
        public readonly OrderStatus $status,
        public readonly int $totalAmount,
        public readonly int $shippingCost,
        public readonly int $taxAmount,
        public readonly ?int $shipmentMethodId,
        public readonly ?string $shipmentMethodCode,
        public readonly array $shippingAddress,
        public readonly ?array $shipmentSnapshot,
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
            userId: $order->user_id,
            status: OrderStatus::from($order->status),
            totalAmount: $order->total_amount,
            shippingCost: $order->shipping_cost,
            taxAmount: $order->tax_amount,
            shipmentMethodId: $order->shipment_method_id,
            shipmentMethodCode: $order->shipment_method_code,
            shippingAddress: $order->shipping_address ?? [],
            shipmentSnapshot: $order->shipment_snapshot,
            transactionRef: $order->transaction_ref,
            notes: $order->notes,
            createdAt: Carbon::parse($order->created_at),
            items: $items,
        );
    }
}
