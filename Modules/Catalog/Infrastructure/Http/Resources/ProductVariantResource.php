<?php

namespace Modules\Catalog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;

/** @mixin ProductVariantDTO */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductVariantDTO $dto */
        $dto = $this->resource;

        return [
            'id'               => $dto->id,
            'sku'              => $dto->sku,
            'is_default'       => $dto->isDefault,
            'base_price'       => $dto->basePrice,
            'compare_at_price' => $dto->compareAtPrice,
            'attributes'       => $dto->attributes,
            'image_url'        => $dto->imageUrl,
        ];
    }
}
