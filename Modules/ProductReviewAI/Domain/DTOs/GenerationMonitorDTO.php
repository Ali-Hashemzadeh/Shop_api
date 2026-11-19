<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

use Carbon\CarbonInterface;

/**
 * Execution view of one generation run for the monitoring API.
 *
 * Every execution field is nullable: historical runs (before execution tracking
 * existed) report null rather than a fabricated value. `status` is the
 * EXECUTION status; draft moderation is separate (`draftCounts`, detail only) —
 * a `completed` run has produced drafts, it has not published anything.
 */
final class GenerationMonitorDTO
{
    /**
     * @param  array<string, int>|null  $draftCounts  detail only: drafts per moderation status
     */
    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly ?string $productUuid,
        public readonly ?string $productTitle,
        public readonly ?int $sourceId,
        public readonly ?string $sourceCode,
        public readonly ?string $sourceName,
        public readonly string $model,
        public readonly ?string $promptName,
        public readonly ?int $promptVersion,
        public readonly int $countRequested,
        public readonly int $countGenerated,
        public readonly string $status,
        public readonly ?string $currentStage,
        public readonly ?string $failureCode,
        public readonly ?string $failureReason,
        public readonly ?string $failedStage,
        public readonly ?bool $failureRetryable,
        public readonly ?int $createdBy,
        public readonly ?CarbonInterface $createdAt,
        public readonly ?CarbonInterface $startedAt,
        public readonly ?CarbonInterface $finishedAt,
        public readonly ?int $durationMs,
        public readonly ?CarbonInterface $lastHeartbeatAt,
        public readonly int $attemptCount,
        public readonly ?int $retryOfId,
        public readonly ?int $retryGenerationId,
        public readonly bool $isSuspectedStuck,
        public readonly bool $canRetry,
        public readonly ?array $draftCounts = null,
    ) {}
}
