<?php

declare(strict_types=1);

namespace Modules\Cart\Domain\DTOs;

use Modules\Cart\Domain\Models\CartItem;

/**
 * One cart line, enriched with live Catalog pricing.
 *
 * Cart never talks to Promotion. It asks Catalog for a variant and Catalog returns
 * a price that already reflects the winning automatic discount, so the dependency
 * stays Cart → Catalog → Promotion. Prices are re-read on every cart response, so
 * a discount that starts or ends between two page loads is reflected immediately —
 * nothing promotional is stored on the cart row.
 */
class CartItemDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $cartId,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly ?string $productName,
        /** Catalog's regular price. */
        public readonly ?int $basePrice,
        /** What this unit actually costs now — base price minus any automatic discount. */
        public readonly ?int $effectivePrice,
        /** The winning rule as a display payload, or null. */
        public readonly ?array $automaticDiscount,
        public readonly ?string $imageUrl,
        /** effectivePrice × quantity — the payable amount for this line. */
        public readonly int $lineTotal,
        /** basePrice × quantity — the strike-through amount. */
        public readonly int $regularLineTotal,
        /** regularLineTotal − lineTotal; 0 when nothing applies. */
        public readonly int $automaticDiscountAmount,
        public readonly array $attributes = [],
        public readonly ?int $availableStock = null,
        public readonly ?int $maxQuantityPerOrder = null,
        public readonly ?int $effectiveMaxQuantity = null,
        public readonly ?int $remainingAddableQuantity = null,
        public readonly bool $quantityValid = true,
        public readonly ?string $type = null,
        public readonly ?string $primaryImageUrl = null,
    ) {}

    public static function fromModel(
        CartItem $item,
        ?string $productName = null,
        ?int $basePrice = null,
        ?int $effectivePrice = null,
        ?array $automaticDiscount = null,
        ?string $imageUrl = null,
        array $attributes = [],
        ?int $availableStock = null,
        ?int $maxQuantityPerOrder = null,
        ?string $type = null,
        ?string $primaryImageUrl = null,
    ): self {
        $effectiveMax = $availableStock !== null
            ? min(max(0, $availableStock), $maxQuantityPerOrder ?? PHP_INT_MAX)
            : $maxQuantityPerOrder;

        // A variant Catalog can no longer resolve has no price at all; the line
        // contributes zero rather than guessing.
        $effectivePrice ??= $basePrice;
        $lineTotal = $effectivePrice !== null ? $item->quantity * $effectivePrice : 0;
        $regularLineTotal = $basePrice !== null ? $item->quantity * $basePrice : 0;

        return new self(
            id: $item->id,
            cartId: $item->cart_id,
            sku: $item->sku,
            quantity: $item->quantity,
            productName: $productName,
            basePrice: $basePrice,
            effectivePrice: $effectivePrice,
            automaticDiscount: $automaticDiscount,
            imageUrl: $imageUrl,
            lineTotal: $lineTotal,
            regularLineTotal: $regularLineTotal,
            automaticDiscountAmount: max(0, $regularLineTotal - $lineTotal),
            attributes: $attributes,
            availableStock: $availableStock,
            maxQuantityPerOrder: $maxQuantityPerOrder,
            effectiveMaxQuantity: $effectiveMax,
            remainingAddableQuantity: $effectiveMax !== null ? max(0, $effectiveMax - $item->quantity) : null,
            quantityValid: $effectiveMax === null || $item->quantity <= $effectiveMax,
            type: $type,
            primaryImageUrl: $primaryImageUrl,
        );
    }
}
