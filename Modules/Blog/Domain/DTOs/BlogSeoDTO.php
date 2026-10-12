<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

/**
 * Resolved SEO metadata for a public post, with every fallback already applied:
 *
 *   title          ← seo_title → title
 *   description    ← seo_description → excerpt
 *   og_title       ← og_title → seo_title → title
 *   og_description ← og_description → seo_description → excerpt
 *   og_image       ← og_media → featured_media
 *   canonical_url  ← canonical_url → {FRONTEND_URL}/{post_path}/{slug}
 */
final class BlogSeoDTO
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $canonicalUrl,
        public readonly string $ogTitle,
        public readonly ?string $ogDescription,
        public readonly ?BlogMediaDTO $ogImage,
        public readonly bool $robotsIndex,
        public readonly bool $robotsFollow,
    ) {}
}
