<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\AdminBlogPostDTO;
use Modules\Blog\Infrastructure\Support\BlogUrls;

/**
 * The editor's post shape: numeric `id` (admin routes are numeric) plus the
 * public code, workflow state, optimistic-lock `version`, raw SEO fields, and
 * every attachment. `content`, `media`, `products`, `related_post_ids` are
 * present only on the detail view.
 *
 * @mixin AdminBlogPostDTO
 */
class AdminBlogPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AdminBlogPostDTO $dto */
        $dto = $this->resource;
        $detail = $dto->content !== null;

        return [
            'id' => $dto->id,
            'public_code' => $dto->publicCode,
            'title' => $dto->title,
            'slug' => $dto->slug,
            'url' => BlogUrls::post($dto->slug),
            'excerpt' => $dto->excerpt,
            'content' => $this->when($detail, $dto->content),
            'status' => $dto->status,
            'is_publicly_visible' => $dto->isPubliclyVisible,
            'published_at' => $dto->publishedAt?->toISOString(),
            'first_published_at' => $dto->firstPublishedAt?->toISOString(),
            'last_published_at' => $dto->lastPublishedAt?->toISOString(),
            'unpublish_at' => $dto->unpublishAt?->toISOString(),
            'is_featured' => $dto->isFeatured,
            'allow_comments' => $dto->allowComments,
            'featured_media' => $dto->featuredMedia === null ? null : new BlogMediaResource($dto->featuredMedia),
            'og_media' => $dto->ogMedia === null ? null : new BlogMediaResource($dto->ogMedia),
            'seo' => $dto->seo,
            'primary_author_id' => $dto->primaryAuthorId,
            'authors' => AdminBlogAuthorResource::collection($dto->authors),
            'categories' => BlogCategoryResource::collection($dto->categories),
            'tags' => BlogTagResource::collection($dto->tags),
            'media' => $this->when($detail, fn () => BlogMediaResource::collection($dto->media)),
            'products' => $this->when($detail, $dto->products),
            'related_post_ids' => $this->when($detail, $dto->relatedPostIds),
            'stats' => $dto->stats,
            'created_by_user_id' => $dto->createdByUserId,
            'updated_by_user_id' => $dto->updatedByUserId,
            'version' => $dto->version,
            'created_at' => $dto->createdAt->toISOString(),
            'updated_at' => $dto->updatedAt->toISOString(),
            'deleted_at' => $dto->deletedAt?->toISOString(),
        ];
    }
}
