<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductReviewAI\Domain\DTOs\ExternalProductMappingDTO;

/**
 * @mixin ExternalProductMappingDTO
 */
class ExternalProductMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ExternalProductMappingDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'product_id' => $dto->productId,
            'source_id' => $dto->sourceId,
            'source_code' => $dto->sourceCode,
            'external_id' => $dto->externalId,
            'external_url' => $dto->externalUrl,
            'metadata' => $dto->metadata,
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
