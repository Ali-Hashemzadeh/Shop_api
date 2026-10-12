<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogPlacementDTO;

/**
 * Admin rows carry the numeric post id; public rows carry the hydrated post
 * card instead (never the internal id).
 *
 * @mixin BlogPlacementDTO
 */
class BlogPlacementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogPlacementDTO $dto */
        $dto = $this->resource;
        $public = $dto->post !== null;

        return [
            'id' => $dto->id,
            'placement_key' => $dto->placementKey,
            'blog_post_id' => $this->when(! $public, $dto->postId),
            'post' => $this->when($public, fn () => new BlogPostSummaryResource($dto->post)),
            'starts_at' => $dto->startsAt?->toISOString(),
            'ends_at' => $dto->endsAt?->toISOString(),
            'sort_order' => $dto->sortOrder,
            'is_active_now' => $dto->isActiveNow,
        ];
    }
}
