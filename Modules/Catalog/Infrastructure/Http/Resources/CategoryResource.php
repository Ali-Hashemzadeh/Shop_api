<?php

namespace Modules\Catalog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalog\Domain\DTOs\CategoryDTO;

/** @mixin CategoryDTO */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CategoryDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'name' => $dto->name,
            'slug' => $dto->slug,
            'is_active' => $dto->isActive,
            'parent_id' => $dto->parentId,
            'image_url' => $dto->imageUrl,
            'parent' => $dto->parent !== null ? new CategoryResource($dto->parent) : null,
            'children' => CategoryResource::collection($dto->children),
        ];
    }
}
