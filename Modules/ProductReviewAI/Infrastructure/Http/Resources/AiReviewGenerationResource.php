<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductReviewAI\Application\Support\DiagnosticSanitizer;
use Modules\ProductReviewAI\Domain\DTOs\AiReviewGenerationDTO;

/**
 * The original per-run shape (product-scoped history, generate/regenerate, and
 * the `generation` object of GET /admin/ai-reviews/{generation}). The
 * execution-tracking keys after `created_at` are additive; every original key
 * is unchanged. `failure_reason` passes through the diagnostic sanitizer.
 *
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
            'failure_reason' => app(DiagnosticSanitizer::class)->text($dto->failureReason, 1000),
            'created_by' => $dto->createdBy,
            'created_at' => $dto->createdAt->toISOString(),
            'current_stage' => $dto->currentStage,
            'failure_code' => $dto->failureCode,
            'failed_stage' => $dto->failedStage,
            'started_at' => $dto->startedAt?->copy()->utc()->toISOString(),
            'finished_at' => $dto->finishedAt?->copy()->utc()->toISOString(),
            'duration_ms' => $dto->durationMs,
            'last_heartbeat_at' => $dto->lastHeartbeatAt?->copy()->utc()->toISOString(),
            'attempt_count' => $dto->attemptCount,
            'retry_of_id' => $dto->retryOfId,
        ];
    }
}
