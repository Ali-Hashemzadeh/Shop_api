<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;

/**
 * The editor's view of a post: raw (un-fallen-back) SEO fields, workflow state,
 * optimistic-lock version, audit columns, and every attachment. `content` is
 * null on list rows — the admin index never loads article HTML.
 */
final class AdminBlogPostDTO
{
    /**
     * @param  array<string, mixed>  $seo
     * @param  list<BlogAuthorDTO>  $authors
     * @param  list<BlogCategoryDTO>  $categories
     * @param  list<BlogTagDTO>  $tags
     * @param  list<BlogMediaDTO>  $media
     * @param  list<array{product_id: int, relation_type: string, sort_order: int}>  $products
     * @param  list<int>  $relatedPostIds
     * @param  array<string, int>  $stats
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicCode,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $excerpt,
        public readonly ?string $content,
        public readonly string $status,
        public readonly ?Carbon $publishedAt,
        public readonly ?Carbon $firstPublishedAt,
        public readonly ?Carbon $lastPublishedAt,
        public readonly ?Carbon $unpublishAt,
        public readonly bool $isFeatured,
        public readonly bool $allowComments,
        public readonly ?BlogMediaDTO $featuredMedia,
        public readonly ?BlogMediaDTO $ogMedia,
        public readonly array $seo,
        public readonly ?int $primaryAuthorId,
        public readonly array $authors,
        public readonly array $categories,
        public readonly array $tags,
        public readonly array $media,
        public readonly array $products,
        public readonly array $relatedPostIds,
        public readonly array $stats,
        public readonly bool $isPubliclyVisible,
        public readonly ?int $createdByUserId,
        public readonly ?int $updatedByUserId,
        public readonly int $version,
        public readonly Carbon $createdAt,
        public readonly Carbon $updatedAt,
        public readonly ?Carbon $deletedAt,
    ) {}
}
