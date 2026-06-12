<?php

namespace Modules\Catalog\Domain\DTOs;

use Modules\Catalog\Domain\Models\ProductImage;

class ProductImageDTO
{
    public function __construct(
        public readonly int    $id,
        public readonly int    $mediaId,
        public readonly string $url,
        public readonly int    $sortOrder,
    ) {}

    public static function fromModel(ProductImage $image, string $url): self
    {
        return new self(
            id:        $image->id,
            mediaId:   $image->media_id,
            url:       $url,
            sortOrder: $image->sort_order,
        );
    }
}
