<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogAuthorDTO;
use Modules\Blog\Domain\DTOs\BlogPostDetailDTO;
use Modules\Blog\Domain\DTOs\BlogRelatedProductDTO;
use Modules\Blog\Infrastructure\Support\BlogUrls;

/**
 * The public article page. `content` is the stored, server-sanitized HTML for
 * the frontend to render as-is. Includes everything a frontend needs for
 * `<title>`, meta description, canonical, OpenGraph/Twitter tags, and ready-made
 * schema.org `BlogPosting` + `BreadcrumbList` JSON-LD. Never exposes editorial
 * notes, audit fields, Identity ids, or draft internals.
 *
 * @mixin BlogPostDetailDTO
 */
class BlogPostDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogPostDetailDTO $dto */
        $dto = $this->resource;
        $summary = $dto->summary;
        $seo = $dto->seo;

        return [
            ...BlogPostSummaryResource::fields($summary),
            'content' => $dto->content,
            'first_published_at' => $dto->firstPublishedAt?->toISOString(),
            'modified_at' => $summary->updatedAt->toISOString(),
            'media' => BlogMediaResource::collection($dto->media),
            'related_products' => array_map(fn (BlogRelatedProductDTO $p): array => [
                'id' => $p->publicCode,
                'product_id' => $p->productId,
                'title' => $p->title,
                'slug' => $p->slug,
                'primary_image_url' => $p->primaryImageUrl,
                'base_price' => $p->basePrice,
                'effective_price' => $p->effectivePrice,
                'rating_average' => $p->ratingAverage,
                'relation_type' => $p->relationType,
            ], $dto->relatedProducts),
            'seo' => [
                'title' => $seo->title,
                'description' => $seo->description,
                'canonical_url' => $seo->canonicalUrl,
                'og_title' => $seo->ogTitle,
                'og_description' => $seo->ogDescription,
                'og_media' => $seo->ogImage === null ? null : new BlogMediaResource($seo->ogImage),
                'robots_index' => $seo->robotsIndex,
                'robots_follow' => $seo->robotsFollow,
                'robots' => ($seo->robotsIndex ? 'index' : 'noindex').','.($seo->robotsFollow ? 'follow' : 'nofollow'),
            ],
            'breadcrumbs' => $dto->breadcrumbs,
            'structured_data' => [
                'article' => $this->articleJsonLd($dto),
                'breadcrumb' => $this->breadcrumbJsonLd($dto),
            ],
            'redirect' => $dto->redirectedFrom === null ? null : [
                'from' => $dto->redirectedFrom,
                'to' => $summary->slug,
                'status' => 301,
            ],
            'is_preview' => $this->when($dto->isPreview, true),
            'status' => $this->when($dto->isPreview, $dto->status),
        ];
    }

    /** @return array<string, mixed> */
    private function articleJsonLd(BlogPostDetailDTO $dto): array
    {
        $summary = $dto->summary;
        $image = $dto->seo->ogImage?->url ?? $summary->featuredMedia?->url;

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $dto->seo->title,
            'description' => $dto->seo->description,
            'image' => $image === null ? null : [$image],
            'datePublished' => $summary->publishedAt?->toIso8601String(),
            'dateModified' => $summary->updatedAt->toIso8601String(),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $dto->seo->canonicalUrl],
            'author' => array_map(fn (BlogAuthorDTO $a): array => [
                '@type' => 'Person',
                'name' => $a->displayName,
                'url' => BlogUrls::author($a->slug),
            ], $summary->authors),
            'keywords' => $summary->tags === [] ? null : implode(', ', array_map(fn ($t) => $t->name, $summary->tags)),
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function breadcrumbJsonLd(BlogPostDetailDTO $dto): array
    {
        $items = [['name' => 'Blog', 'url' => BlogUrls::blogHome()]];
        foreach ($dto->breadcrumbs as $crumb) {
            $items[] = ['name' => $crumb['name'], 'url' => BlogUrls::category($crumb['slug'])];
        }
        $items[] = ['name' => $dto->summary->title, 'url' => $dto->seo->canonicalUrl];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $item, int $i): array => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ], $items, array_keys($items)),
        ];
    }
}
