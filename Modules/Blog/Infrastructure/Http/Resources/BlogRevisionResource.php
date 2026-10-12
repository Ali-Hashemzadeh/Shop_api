<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogRevisionDTO;

/** @mixin BlogRevisionDTO */
class BlogRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogRevisionDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'post_id' => $dto->postId,
            'revision_number' => $dto->revisionNumber,
            'title' => $dto->title,
            'slug' => $dto->slug,
            'excerpt' => $dto->excerpt,
            'content' => $this->when($dto->content !== null, $dto->content),
            'featured_media_id' => $dto->featuredMediaId,
            'status_snapshot' => $dto->statusSnapshot,
            'seo_snapshot' => $dto->seoSnapshot,
            'taxonomy_snapshot' => $dto->taxonomySnapshot,
            'author_snapshot' => $dto->authorSnapshot,
            'created_by_user_id' => $dto->createdByUserId,
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
