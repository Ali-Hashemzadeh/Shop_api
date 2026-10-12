<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Modules\Catalog\Domain\DTOs\ProductDTO;

/**
 * A product linked from an article, hydrated live from Catalog's ProductDTO —
 * Blog never stores a price, so what a reader sees is always current pricing.
 */
final class BlogRelatedProductDTO
{
    public function __construct(
        public readonly int $productId,
        public readonly string $publicCode,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $primaryImageUrl,
        public readonly ?int $basePrice,
        public readonly ?int $effectivePrice,
        public readonly ?float $ratingAverage,
        public readonly string $relationType,
        public readonly int $sortOrder,
    ) {}

    public static function fromProduct(ProductDTO $product, string $relationType, int $sortOrder): self
    {
        $default = null;
        foreach ($product->variants as $variant) {
            if ($variant->isDefault) {
                $default = $variant;
                break;
            }
        }
        $default ??= $product->variants[0] ?? null;

        return new self(
            productId: $product->id,
            publicCode: $product->uuid,
            title: $product->title,
            slug: $product->slug,
            primaryImageUrl: $product->primaryImageUrl,
            basePrice: $default?->basePrice,
            effectivePrice: $default?->effectivePrice(),
            ratingAverage: $product->ratingAverage(),
            relationType: $relationType,
            sortOrder: $sortOrder,
        );
    }
}
