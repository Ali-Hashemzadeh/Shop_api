<?php

namespace Modules\Catalog\Domain\DTOs;

use Modules\Catalog\Domain\Models\Category;

class CategoryDTO
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $slug,
        public readonly bool    $isActive,
        public readonly ?int    $parentId,
        public readonly ?string $imageUrl,
    ) {}

    public static function fromModel(Category $category, ?string $imageUrl = null): self
    {
        return new self(
            id:       $category->id,
            name:     $category->name,
            slug:     $category->slug,
            isActive: $category->is_active,
            parentId: $category->parent_id,
            imageUrl: $imageUrl,
        );
    }
}
