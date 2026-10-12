<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogCollectionDTO;

/** @mixin BlogCollectionDTO */
class BlogCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogCollectionDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'name' => $dto->name,
            'slug' => $dto->slug,
            'description' => $dto->description,
            'is_active' => $dto->isActive,
            'starts_at' => $dto->startsAt?->toISOString(),
            'ends_at' => $dto->endsAt?->toISOString(),
            'posts' => $this->when($dto->posts !== null, fn () => BlogPostSummaryResource::collection($dto->posts ?? [])),
            'post_ids' => $this->when($dto->postIds !== null, $dto->postIds),
            'created_at' => $dto->createdAt->toISOString(),
            'updated_at' => $dto->updatedAt->toISOString(),
        ];
    }
}
