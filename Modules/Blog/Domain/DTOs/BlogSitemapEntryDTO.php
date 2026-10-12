<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

/**
 * One indexable Blog URL for the application's sitemap layer. `type` is
 * post|category|tag|author; `url` is the absolute storefront URL.
 */
final class BlogSitemapEntryDTO
{
    public function __construct(
        public readonly string $type,
        public readonly string $slug,
        public readonly string $url,
        public readonly ?string $publishedAt,
        public readonly string $updatedAt,
        public readonly bool $indexable,
    ) {}
}
