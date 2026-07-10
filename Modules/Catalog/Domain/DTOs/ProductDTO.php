<?php

namespace Modules\Catalog\Domain\DTOs;

use Modules\Catalog\Domain\Models\Product;

class ProductDTO
{
    /**
     * @param  ProductImageDTO[]  $images
     * @param  ProductVariantDTO[]  $variants
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?array $features,
        public readonly string $status,
        public readonly int $salesCount,
        public readonly ?int $categoryId,
        public readonly ?string $primaryImageUrl,
        public readonly array $images,
        public readonly array $variants,
    ) {}

    /**
     * @param  ProductImageDTO[]  $images
     * @param  ProductVariantDTO[]  $variants
     */
    public static function fromModel(
        Product $product,
        ?string $primaryImageUrl,
        array $images,
        array $variants,
    ): self {
        return new self(
            id: $product->id,
            uuid: $product->uuid,
            title: $product->title,
            slug: $product->slug,
            description: $product->description,
            features: $product->features,
            status: $product->status,
            salesCount: (int) ($product->sales_count ?? 0),
            categoryId: $product->category_id,
            primaryImageUrl: $primaryImageUrl,
            images: $images,
            variants: $variants,
        );
    }
}
