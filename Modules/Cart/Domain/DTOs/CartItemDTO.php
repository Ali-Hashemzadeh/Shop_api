<?php

declare(strict_types=1);

namespace Modules\Cart\Domain\DTOs;

use Modules\Cart\Domain\Models\CartItem;

class CartItemDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $cartId,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly ?string $productName,
        public readonly ?int $basePrice,
        public readonly ?int $compareAtPrice,
        public readonly ?string $imageUrl,
        public readonly int $lineTotal,
    ) {}

    public static function fromModel(
        CartItem $item,
        ?string $productName = null,
        ?int $basePrice = null,
        ?int $compareAtPrice = null,
        ?string $imageUrl = null,
    ): self {
        return new self(
            id: $item->id,
            cartId: $item->cart_id,
            sku: $item->sku,
            quantity: $item->quantity,
            productName: $productName,
            basePrice: $basePrice,
            compareAtPrice: $compareAtPrice,
            imageUrl: $imageUrl,
            lineTotal: $basePrice !== null ? $item->quantity * $basePrice : 0,
        );
    }

    /** Return a new instance enriched with Catalog pricing data. */
    public function withCatalogData(
        ?string $productName,
        ?int $basePrice,
        ?int $compareAtPrice,
        ?string $imageUrl,
    ): self {
        return new self(
            id: $this->id,
            cartId: $this->cartId,
            sku: $this->sku,
            quantity: $this->quantity,
            productName: $productName,
            basePrice: $basePrice,
            compareAtPrice: $compareAtPrice,
            imageUrl: $imageUrl,
            lineTotal: $basePrice !== null ? $this->quantity * $basePrice : 0,
        );
    }
}
