<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;

/**
 * Immutable view of one generation run.
 *
 * The execution-tracking fields (stage, structured failure, timestamps, retry
 * link) were added later and are additive; historical runs carry null.
 */
class AiReviewGenerationDTO
{
    /**
     * @param  array<string, mixed>|null  $analysis
     */
    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly ?int $sourceId,
        public readonly string $model,
        public readonly int $countRequested,
        public readonly string $status,
        public readonly ?array $analysis,
        public readonly ?string $promptName,
        public readonly ?int $promptVersion,
        public readonly ?int $createdBy,
        public readonly ?string $failureReason,
        public readonly Carbon $createdAt,
        public readonly ?string $currentStage = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failedStage = null,
        public readonly ?CarbonInterface $startedAt = null,
        public readonly ?CarbonInterface $finishedAt = null,
        public readonly ?int $durationMs = null,
        public readonly ?CarbonInterface $lastHeartbeatAt = null,
        public readonly int $attemptCount = 0,
        public readonly ?int $retryOfId = null,
    ) {}

    public static function fromModel(AiReviewGeneration $g): self
    {
        return new self(
            id: (int) $g->id,
            productId: (int) $g->product_id,
            sourceId: $g->source_id !== null ? (int) $g->source_id : null,
            model: (string) $g->model,
            countRequested: (int) $g->count_requested,
            status: (string) ($g->status instanceof \BackedEnum ? $g->status->value : $g->status),
            analysis: $g->analysis_json,
            promptName: $g->prompt_name,
            promptVersion: $g->prompt_version !== null ? (int) $g->prompt_version : null,
            createdBy: $g->created_by !== null ? (int) $g->created_by : null,
            failureReason: $g->failure_reason,
            createdAt: Carbon::parse($g->created_at),
            currentStage: $g->current_stage,
            failureCode: $g->failure_code,
            failedStage: $g->failed_stage,
            startedAt: $g->started_at,
            finishedAt: $g->finished_at,
            durationMs: $g->duration_ms !== null ? (int) $g->duration_ms : null,
            lastHeartbeatAt: $g->last_heartbeat_at,
            attemptCount: (int) ($g->attempt_count ?? 0),
            retryOfId: $g->retry_of_id !== null ? (int) $g->retry_of_id : null,
        );
    }
}
