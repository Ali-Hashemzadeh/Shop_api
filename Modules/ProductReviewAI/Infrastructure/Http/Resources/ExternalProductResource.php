<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductReviewAI\Domain\DTOs\ExternalProductDTO;

/**
 * @mixin ExternalProductDTO
 */
class ExternalProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ExternalProductDTO $dto */
        $dto = $this->resource;

        return [
            'external_id' => $dto->externalId,
            'title' => $dto->title,
            'url' => $dto->url,
        ];
    }
}
