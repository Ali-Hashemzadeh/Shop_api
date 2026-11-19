<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductReviewAI\Domain\DTOs\GenerationMonitorDTO;
use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;

/**
 * Execution view of a generation run (global list, detail, retry response).
 * Nullable fields are null when not applicable or not recorded (historical runs).
 * `drafts` (moderation counts) is present on the detail endpoint only.
 *
 * @mixin GenerationMonitorDTO
 */
class GenerationMonitorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GenerationMonitorDTO $dto */
        $dto = $this->resource;

        $data = [
            'id' => $dto->id,
            'status' => $dto->status,
            'current_stage' => $dto->currentStage,
            'product' => [
                'id' => $dto->productId,
                'uuid' => $dto->productUuid,
                'title' => $dto->productTitle,
            ],
            'source' => [
                'id' => $dto->sourceId,
                'code' => $dto->sourceCode,
                'name' => $dto->sourceName,
            ],
            'model' => $dto->model,
            'prompt_name' => $dto->promptName,
            'prompt_version' => $dto->promptVersion,
            'count_requested' => $dto->countRequested,
            'count_generated' => $dto->countGenerated,
            'failure' => $dto->status === 'failed' ? [
                'code' => $dto->failureCode,
                'message' => $dto->failureReason
                    ?? ($dto->failureCode !== null ? GenerationFailureCode::tryFrom($dto->failureCode)?->description() : null),
                'stage' => $dto->failedStage,
                'retryable' => $dto->failureRetryable,
            ] : null,
            'created_by' => $dto->createdBy,
            'created_at' => $this->iso($dto->createdAt),
            'started_at' => $this->iso($dto->startedAt),
            'finished_at' => $this->iso($dto->finishedAt),
            'duration_ms' => $dto->durationMs,
            'last_heartbeat_at' => $this->iso($dto->lastHeartbeatAt),
            'attempt_count' => $dto->attemptCount,
            'retry_of_id' => $dto->retryOfId,
            'retry_generation_id' => $dto->retryGenerationId,
            'is_suspected_stuck' => $dto->isSuspectedStuck,
            'can_retry' => $dto->canRetry,
        ];

        if ($dto->draftCounts !== null) {
            $data['drafts'] = $dto->draftCounts;
        }

        return $data;
    }

    private function iso(?CarbonInterface $at): ?string
    {
        return $at?->copy()->utc()->toISOString();
    }
}
