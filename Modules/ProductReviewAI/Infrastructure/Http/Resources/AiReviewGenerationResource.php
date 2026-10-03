<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductReviewAI\Domain\DTOs\AiReviewGenerationDTO;

/**
 * @mixin AiReviewGenerationDTO
 */
class AiReviewGenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AiReviewGenerationDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'product_id' => $dto->productId,
            'source_id' => $dto->sourceId,
            'model' => $dto->model,
            'count_requested' => $dto->countRequested,
            'status' => $dto->status,
            'analysis' => $dto->analysis,
            'prompt_name' => $dto->promptName,
            'prompt_version' => $dto->promptVersion,
            'failure_reason' => $dto->failureReason,
            'created_by' => $dto->createdBy,
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
