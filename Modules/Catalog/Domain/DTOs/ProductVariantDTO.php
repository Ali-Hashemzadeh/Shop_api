<?php

namespace Modules\Catalog\Domain\DTOs;

use Modules\Catalog\Domain\Models\ProductVariant;

class ProductVariantDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $type,
        public readonly bool $isDefault,
        public readonly int $basePrice,
        public readonly ?int $compareAtPrice,
        public readonly ?int $maxQuantityPerOrder,
        public readonly array $attributes,
        public readonly ?string $imageUrl,
        public readonly ?string $productName = null,
        // Available units for this variant's SKU (physical − reserved), resolved from
        // the Inventory module. Null when a caller builds the DTO without enrichment.
        public readonly ?int $availableStock = null,
    ) {}

    public static function fromModel(
        ProductVariant $variant,
        ?string $imageUrl = null,
        ?string $productName = null,
        ?int $availableStock = null,
    ): self {
        return new self(
            id: $variant->id,
            sku: $variant->sku,
            type: $variant->type,
            isDefault: $variant->is_default,
            basePrice: $variant->base_price,
            compareAtPrice: $variant->compare_at_price,
            maxQuantityPerOrder: $variant->max_quantity_per_order,
            attributes: $variant->attributes ?? [],
            imageUrl: $imageUrl,
            productName: $productName,
            availableStock: $availableStock,
        );
    }
}
