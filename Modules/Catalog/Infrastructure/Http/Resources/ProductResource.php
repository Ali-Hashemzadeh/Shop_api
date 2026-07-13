<?php

namespace Modules\Catalog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalog\Domain\DTOs\ProductDTO;

/** @mixin ProductDTO */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductDTO $dto */
        $dto = $this->resource;

        return [
            // Public identifier is the opaque UUID; the internal integer id is not exposed.
            'id' => $dto->uuid,
            'title' => $dto->title,
            'slug' => $dto->slug,
            'description' => $dto->description,
            'features' => $dto->features,
            'status' => $dto->status,
            'sales_count' => $dto->salesCount,
            'category_id' => $dto->categoryId,
            'brand_id' => $dto->brandId,
            'primary_image_url' => $dto->primaryImageUrl,
            'images' => ProductImageResource::collection($dto->images),
            'variants' => ProductVariantResource::collection($dto->variants),
        ];
    }
}
