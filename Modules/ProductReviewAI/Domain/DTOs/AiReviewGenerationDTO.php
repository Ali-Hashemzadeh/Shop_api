<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

use Carbon\Carbon;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;

/**
 * Immutable view of one generation run.
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
        );
    }
}
