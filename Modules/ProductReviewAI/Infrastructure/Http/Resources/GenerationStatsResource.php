<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductReviewAI\Domain\DTOs\GenerationStatsDTO;

/**
 * Dashboard aggregates. `success_rate` is always a fraction 0..1 (or null when
 * nothing has finished); `average_duration_ms` is null when no completed run
 * has a recorded duration.
 *
 * @mixin GenerationStatsDTO
 */
class GenerationStatsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GenerationStatsDTO $dto */
        $dto = $this->resource;

        return [
            'total' => $dto->total,
            'pending' => $dto->pending,
            'processing' => $dto->processing,
            'completed' => $dto->completed,
            'failed' => $dto->failed,
            'suspected_stuck' => $dto->suspectedStuck,
            'success_rate' => $dto->successRate,
            'average_duration_ms' => $dto->averageDurationMs,
            'duration_sample_size' => $dto->durationSampleSize,
            'thresholds' => $dto->thresholds,
        ];
    }
}
