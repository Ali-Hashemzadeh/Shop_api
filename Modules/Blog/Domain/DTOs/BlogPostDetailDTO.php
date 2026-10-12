<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;

/**
 * The public article page: the summary plus sanitized HTML content, Blog media,
 * live-hydrated related products, resolved SEO, and breadcrumb trail. Never
 * carries editorial notes, audit fields, Identity ids, or draft internals.
 *
 * `redirectedFrom` is set when the post was reached through a retired slug, so
 * the frontend can 301 to the canonical slug.
 */
final class BlogPostDetailDTO
{
    /**
     * @param  list<BlogMediaDTO>  $media
     * @param  list<BlogRelatedProductDTO>  $relatedProducts
     * @param  list<array{name: string, slug: string}>  $breadcrumbs
     */
    public function __construct(
        public readonly BlogPostSummaryDTO $summary,
        public readonly string $content,
        public readonly ?Carbon $firstPublishedAt,
        public readonly ?Carbon $lastPublishedAt,
        public readonly array $media,
        public readonly array $relatedProducts,
        public readonly BlogSeoDTO $seo,
        public readonly array $breadcrumbs,
        public readonly ?string $redirectedFrom = null,
        public readonly bool $isPreview = false,
        public readonly ?string $status = null,
    ) {}
}
