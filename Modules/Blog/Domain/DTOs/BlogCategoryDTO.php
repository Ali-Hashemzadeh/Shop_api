<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;
use Modules\Blog\Domain\Models\BlogCategory;

/**
 * A Blog category. `isPrimary` is set only when the category is rendered as an
 * attachment of a post; `children` only on tree responses.
 */
final class BlogCategoryDTO
{
    /**
     * @param  list<BlogCategoryDTO>|null  $children
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?BlogMediaDTO $image,
        public readonly bool $isActive,
        public readonly int $sortOrder,
        public readonly ?string $seoTitle,
        public readonly ?string $seoDescription,
        public readonly Carbon $createdAt,
        public readonly Carbon $updatedAt,
        public readonly ?bool $isPrimary = null,
        public readonly ?array $children = null,
    ) {}

    /**
     * @param  list<BlogCategoryDTO>|null  $children
     */
    public static function fromModel(
        BlogCategory $category,
        ?BlogMediaDTO $image = null,
        ?bool $isPrimary = null,
        ?array $children = null,
    ): self {
        return new self(
            id: (int) $category->id,
            parentId: $category->parent_id !== null ? (int) $category->parent_id : null,
            name: (string) $category->name,
            slug: (string) $category->slug,
            description: $category->description,
            image: $image,
            isActive: (bool) $category->is_active,
            sortOrder: (int) $category->sort_order,
            seoTitle: $category->seo_title,
            seoDescription: $category->seo_description,
            createdAt: Carbon::parse($category->created_at),
            updatedAt: Carbon::parse($category->updated_at),
            isPrimary: $isPrimary,
            children: $children,
        );
    }
}
