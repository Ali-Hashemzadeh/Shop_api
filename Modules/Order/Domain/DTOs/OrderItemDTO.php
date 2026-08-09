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
        /** Catalog's base_price at checkout — the strike-through figure. */
        public readonly int $regularPricePerUnit,
        /** The winning automatic reduction, per unit; 0 when none applied. */
        public readonly int $automaticDiscountAmountPerUnit,
        /** Frozen, self-contained record of the winning rule, or null. */
        public readonly ?array $automaticDiscountSnapshot,
        /** Actually charged per unit, before any order-level coupon. */
        public readonly int $pricePerUnit,
        /** Legacy Catalog cross-out price; null on every order placed since promotions landed. */
        public readonly ?int $compareAtPrice,
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
            regularPricePerUnit: (int) $item->regular_price_per_unit,
            automaticDiscountAmountPerUnit: (int) $item->automatic_discount_amount_per_unit,
            automaticDiscountSnapshot: $item->automatic_discount_snapshot,
            pricePerUnit: $item->price_per_unit,
            compareAtPrice: $item->compare_at_price,
            lineTotal: $item->line_total,
        );
    }
}
