<?php

namespace Modules\Catalog\Domain\DTOs;

use Modules\Catalog\Domain\Models\Brand;

class BrandDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly bool $isActive,
        public readonly ?string $imageUrl,
    ) {}

    public static function fromModel(Brand $brand, ?string $imageUrl = null): self
    {
        return new self(
            id: $brand->id,
            name: $brand->name,
            slug: $brand->slug,
            isActive: $brand->is_active,
            imageUrl: $imageUrl,
        );
    }
}
