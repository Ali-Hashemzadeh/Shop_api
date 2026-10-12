<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;

/**
 * The list-card shape of a post. Deliberately carries no article HTML — list
 * endpoints never load or render `content`. `id` is the internal key for
 * in-module use only; public resources expose `publicCode`.
 */
final class BlogPostSummaryDTO
{
    /**
     * @param  list<BlogAuthorDTO>  $authors
     * @param  list<BlogCategoryDTO>  $categories
     * @param  list<BlogTagDTO>  $tags
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicCode,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $excerpt,
        public readonly ?BlogMediaDTO $featuredMedia,
        public readonly array $authors,
        public readonly array $categories,
        public readonly array $tags,
        public readonly ?Carbon $publishedAt,
        public readonly Carbon $updatedAt,
        public readonly bool $isFeatured,
        public readonly bool $allowComments,
        public readonly int $likesCount,
        public readonly int $commentsCount,
        public readonly int $viewsCount,
        public readonly bool $isLiked,
        public readonly bool $isBookmarked,
    ) {}
}
