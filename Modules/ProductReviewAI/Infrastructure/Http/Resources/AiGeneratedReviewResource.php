<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductReviewAI\Domain\DTOs\AiGeneratedReviewDTO;

/**
 * @mixin AiGeneratedReviewDTO
 */
class AiGeneratedReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AiGeneratedReviewDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'generation_id' => $dto->generationId,
            'product_id' => $dto->productId,
            'name' => $dto->name,
            'rating' => $dto->rating,
            'title' => $dto->title,
            'body' => $dto->body,
            'status' => $dto->status,
            'approved_by' => $dto->approvedBy,
            'approved_at' => $dto->approvedAt?->toISOString(),
            'published_review_id' => $dto->publishedReviewId,
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
