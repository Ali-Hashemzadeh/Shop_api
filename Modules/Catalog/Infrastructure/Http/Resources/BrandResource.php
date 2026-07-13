<?php

namespace Modules\Catalog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalog\Domain\DTOs\BrandDTO;

/** @mixin BrandDTO */
class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BrandDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'name' => $dto->name,
            'slug' => $dto->slug,
            'is_active' => $dto->isActive,
            'image_url' => $dto->imageUrl,
        ];
    }
}
