<?php

namespace Modules\Catalog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalog\Domain\DTOs\ProductImageDTO;

/** @mixin ProductImageDTO */
class ProductImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductImageDTO $dto */
        $dto = $this->resource;

        return [
            'id'         => $dto->id,
            'media_id'   => $dto->mediaId,
            'url'        => $dto->url,
            'sort_order' => $dto->sortOrder,
        ];
    }
}
