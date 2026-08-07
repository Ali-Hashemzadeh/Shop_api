<?php

namespace Modules\Catalog\Domain\DTOs;

use Modules\Catalog\Domain\Models\Category;

class CategoryDTO
{
    public function __construct(
        public readonly int $id,
        /** Customer-facing code (`bdc-XXXXXX`); the integer id is unchanged. */
        public readonly ?string $publicCode,
        public readonly string $name,
        public readonly string $slug,
        public readonly bool $isActive,
        public readonly ?int $parentId,
        public readonly ?string $imageUrl,
        public readonly ?self $parent = null,
        public readonly array $children = [],
    ) {}

    public static function fromModel(
        Category $category,
        ?string $imageUrl = null,
        ?CategoryDTO $parent = null,
        array $children = [],
    ): self {
        return new self(
            id: $category->id,
            publicCode: $category->public_code,
            name: $category->name,
            slug: $category->slug,
            isActive: $category->is_active,
            parentId: $category->parent_id,
            imageUrl: $imageUrl,
            parent: $parent,
            children: $children,
        );
    }
}
