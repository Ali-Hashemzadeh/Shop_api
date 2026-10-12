<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogCategoryDTO;
use Modules\Blog\Infrastructure\Support\BlogUrls;

/** @mixin BlogCategoryDTO */
class BlogCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogCategoryDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'parent_id' => $dto->parentId,
            'name' => $dto->name,
            'slug' => $dto->slug,
            'url' => BlogUrls::category($dto->slug),
            'description' => $dto->description,
            'image' => $dto->image === null ? null : new BlogMediaResource($dto->image),
            'is_active' => $dto->isActive,
            'sort_order' => $dto->sortOrder,
            'is_primary' => $this->when($dto->isPrimary !== null, $dto->isPrimary),
            'seo' => [
                'title' => $dto->seoTitle ?: $dto->name,
                'description' => $dto->seoDescription ?: $dto->description,
                'canonical_url' => BlogUrls::category($dto->slug),
            ],
            'children' => $this->when($dto->children !== null, fn () => self::collection($dto->children ?? [])),
            'created_at' => $dto->createdAt->toISOString(),
            'updated_at' => $dto->updatedAt->toISOString(),
        ];
    }
}
