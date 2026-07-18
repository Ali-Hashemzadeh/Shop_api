<?php

declare(strict_types=1);

namespace Modules\Order\Domain\DTOs;

use Modules\Order\Domain\Models\OrderItem;

class OrderItemDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly string $sku,
        public readonly string $productTitle,
        public readonly array $variantAttributes,
        public readonly ?array $productSnapshot,
        public readonly int $quantity,
        public readonly ?int $maxQuantityPerOrderSnapshot,
        public readonly int $pricePerUnit,
        public readonly int $lineTotal,
    ) {}

    public static function fromModel(OrderItem $item): self
    {
        return new self(
            id: $item->id,
            orderId: $item->order_id,
            sku: $item->sku,
            productTitle: $item->product_title,
            variantAttributes: $item->variant_attributes ?? [],
            productSnapshot: $item->product_snapshot,
            quantity: $item->quantity,
            maxQuantityPerOrderSnapshot: $item->max_quantity_per_order_snapshot,
            pricePerUnit: $item->price_per_unit,
            lineTotal: $item->line_total,
        );
    }
}
