<?php

namespace Modules\Catalog\Domain\DTOs;

use Modules\Catalog\Domain\Models\ProductVariant;

class ProductVariantDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly bool $isDefault,
        public readonly int $basePrice,
        public readonly ?int $compareAtPrice,
        public readonly array $attributes,
        public readonly ?string $imageUrl,
    ) {}

    public static function fromModel(ProductVariant $variant, ?string $imageUrl = null): self
    {
        return new self(
            id: $variant->id,
            sku: $variant->sku,
            isDefault: $variant->is_default,
            basePrice: $variant->base_price,
            compareAtPrice: $variant->compare_at_price,
            attributes: $variant->attributes ?? [],
            imageUrl: $imageUrl,
        );
    }
}
