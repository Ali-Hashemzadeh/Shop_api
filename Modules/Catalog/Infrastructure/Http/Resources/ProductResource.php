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
            'id'                => $dto->id,
            'title'             => $dto->title,
            'slug'              => $dto->slug,
            'description'       => $dto->description,
            'status'            => $dto->status,
            'category_id'       => $dto->categoryId,
            'primary_image_url' => $dto->primaryImageUrl,
            'images'            => ProductImageResource::collection($dto->images),
            'variants'          => ProductVariantResource::collection($dto->variants),
        ];
    }
}
