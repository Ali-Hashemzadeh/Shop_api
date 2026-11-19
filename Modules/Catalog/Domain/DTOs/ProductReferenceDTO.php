<?php

namespace Modules\Catalog\Domain\DTOs;

use Modules\Catalog\Domain\Models\Product;

/**
 * A minimal, enrichment-free product reference (internal id → public code +
 * title + status) for modules that hold loose product ids and only need to
 * label them — e.g. ProductReviewAI's generation monitoring. No media, stock or
 * pricing is resolved, so a page-wide batch costs one small query.
 */
class ProductReferenceDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $title,
        public readonly string $status,
    ) {}

    public static function fromModel(Product $product): self
    {
        return new self(
            id: (int) $product->id,
            uuid: (string) $product->uuid,
            title: (string) $product->title,
            status: (string) $product->status,
        );
    }
}
