<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogTagDTO;
use Modules\Blog\Infrastructure\Support\BlogUrls;

/** @mixin BlogTagDTO */
class BlogTagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogTagDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'name' => $dto->name,
            'slug' => $dto->slug,
            'url' => BlogUrls::tag($dto->slug),
            'is_active' => $dto->isActive,
            'created_at' => $dto->createdAt->toISOString(),
            'updated_at' => $dto->updatedAt->toISOString(),
        ];
    }
}
