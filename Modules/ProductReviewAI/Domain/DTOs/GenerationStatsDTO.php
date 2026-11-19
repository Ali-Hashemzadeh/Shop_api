<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

/**
 * Dashboard aggregates over the (optionally filtered) set of generation runs.
 *
 * successRate = completed / (completed + failed), a fraction 0..1, or null when
 * no run has finished. averageDurationMs averages only completed runs with a
 * recorded duration (historical runs without one are excluded, not counted as
 * zero), or null. suspectedStuck uses StuckGenerationPolicy — the same rule as
 * the per-row flag and the detection command.
 */
final class GenerationStatsDTO
{
    /**
     * @param  array<string, int>  $thresholds
     */
    public function __construct(
        public readonly int $total,
        public readonly int $pending,
        public readonly int $processing,
        public readonly int $completed,
        public readonly int $failed,
        public readonly int $suspectedStuck,
        public readonly ?float $successRate,
        public readonly ?int $averageDurationMs,
        public readonly int $durationSampleSize,
        public readonly array $thresholds,
    ) {}
}
