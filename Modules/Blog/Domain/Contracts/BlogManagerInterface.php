<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Contracts;

use Modules\Blog\Domain\DTOs\BlogPostSummaryDTO;
use Modules\Blog\Domain\DTOs\BlogSitemapEntryDTO;

/**
 * The Blog module's public, cross-module contract. Other modules never read
 * Blog tables; they ask here and receive DTOs or primitives.
 *
 * Dependency direction: Blog → Catalog/Media/Identity (contracts), and
 * Notification → Blog (this contract + Blog's integration events). Catalog does
 * NOT depend on Blog — "articles about this product" is answered by Blog itself
 * (`getPublishedPostsForProduct()` / `GET /api/v1/blog/posts?product_id=`), which
 * keeps the graph acyclic.
 */
interface BlogManagerInterface
{
    /**
     * Indexable storefront URLs for the sitemap layer: published, indexable
     * posts; active categories; active tags and authors when enabled in
     * `config('blog.sitemap')`.
     *
     * @return list<BlogSitemapEntryDTO>
     */
    public function getSitemapEntries(): array;

    /**
     * Distinct ids of readers following any of the given Blog subjects — the
     * audience for a "new article" notification. Ids only.
     *
     * @param  list<int>  $categoryIds
     * @param  list<int>  $tagIds
     * @param  list<int>  $authorIds
     * @return list<int>
     */
    public function getSubscriberUserIds(array $categoryIds, array $tagIds, array $authorIds): array;

    /**
     * Publicly visible articles linked to a Catalog product (shoppable content),
     * newest first.
     *
     * @return list<BlogPostSummaryDTO>
     */
    public function getPublishedPostsForProduct(int $productId, int $limit = 6): array;
}
