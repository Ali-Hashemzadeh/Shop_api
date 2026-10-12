<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogPostSummaryDTO;
use Modules\Blog\Infrastructure\Support\BlogUrls;

/**
 * Article card. `id` is the public code (`bdb-XXXXXX`); the integer key never
 * leaves the module. No article HTML.
 *
 * @mixin BlogPostSummaryDTO
 */
class BlogPostSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogPostSummaryDTO $dto */
        $dto = $this->resource;

        return self::fields($dto);
    }

    /** @return array<string, mixed> */
    public static function fields(BlogPostSummaryDTO $dto): array
    {
        return [
            'id' => $dto->publicCode,
            'title' => $dto->title,
            'slug' => $dto->slug,
            'url' => BlogUrls::post($dto->slug),
            'excerpt' => $dto->excerpt,
            'featured_media' => $dto->featuredMedia === null ? null : new BlogMediaResource($dto->featuredMedia),
            'authors' => BlogAuthorResource::collection($dto->authors),
            'categories' => BlogCategoryResource::collection($dto->categories),
            'tags' => BlogTagResource::collection($dto->tags),
            'published_at' => $dto->publishedAt?->toISOString(),
            'updated_at' => $dto->updatedAt->toISOString(),
            'is_featured' => $dto->isFeatured,
            'allow_comments' => $dto->allowComments,
            'likes_count' => $dto->likesCount,
            'comments_count' => $dto->commentsCount,
            'views_count' => $dto->viewsCount,
            'is_liked' => $dto->isLiked,
            'is_bookmarked' => $dto->isBookmarked,
        ];
    }
}
