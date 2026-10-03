<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductReviewAI\Domain\DTOs\GenerationDetailDTO;

/**
 * A generation run together with its drafts.
 *
 * @mixin GenerationDetailDTO
 */
class GenerationDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GenerationDetailDTO $dto */
        $dto = $this->resource;

        return [
            'generation' => (new AiReviewGenerationResource($dto->generation))->toArray($request),
            'reviews' => AiGeneratedReviewResource::collection($dto->drafts)->toArray($request),
        ];
    }
}
