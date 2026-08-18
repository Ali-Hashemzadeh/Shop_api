<?php

namespace Modules\Catalog\Domain\DTOs;

use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Promotion\Domain\DTOs\AutomaticDiscountResultDTO;

class ProductVariantDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $type,
        public readonly bool $isDefault,
        /** The regular price. Catalog owns only this — promotions live in Promotion. */
        public readonly int $basePrice,
        public readonly ?int $maxQuantityPerOrder,
        public readonly array $attributes,
        public readonly ?string $imageUrl,
        public readonly ?string $productName = null,
        // Available units for this variant's SKU (physical − reserved), resolved from
        // the Inventory module. Null when a caller builds the DTO without enrichment.
        public readonly ?int $availableStock = null,
        public readonly ?string $productPrimaryImageUrl = null,
        /**
         * The single winning automatic discount for this variant right now, or null.
         * Evaluated live on every read — never stored on the variant — so disabling a
         * discount takes effect immediately everywhere it is displayed.
         */
        public readonly ?AutomaticDiscountResultDTO $automaticDiscount = null,
        public readonly ?int $productId = null,
        public readonly array $categoryIds = [],
    ) {}

    /**
     * The price actually charged: base price minus the winning automatic discount.
     *
     * Server-authoritative. Cart line totals, checkout, and every displayed price
     * come from here, so the frontend never recomputes a promotional price and can
     * never disagree with what the customer is billed.
     */
    public function effectivePrice(): int
    {
        return $this->automaticDiscount?->effectivePrice ?? $this->basePrice;
    }

    public static function fromModel(
        ProductVariant $variant,
        ?string $imageUrl = null,
        ?string $productName = null,
        ?int $availableStock = null,
        ?string $productPrimaryImageUrl = null,
        ?AutomaticDiscountResultDTO $automaticDiscount = null,
        ?int $productId = null,
        array $categoryIds = [],
    ): self {
        return new self(
            id: $variant->id,
            sku: $variant->sku,
            type: $variant->type,
            isDefault: $variant->is_default,
            basePrice: $variant->base_price,
            maxQuantityPerOrder: $variant->max_quantity_per_order,
            attributes: $variant->attributes ?? [],
            imageUrl: $imageUrl,
            productName: $productName,
            availableStock: $availableStock,
            productPrimaryImageUrl: $productPrimaryImageUrl,
            automaticDiscount: $automaticDiscount,
            productId: $productId ?? ($variant->product_id ? (int) $variant->product_id : null),
            categoryIds: $categoryIds,
        );
    }
}
