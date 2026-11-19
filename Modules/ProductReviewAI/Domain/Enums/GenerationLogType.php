<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Enums;

/**
 * The kinds of events written to ai_review_generation_logs.
 *
 * Logging is total, not failure-only: every external and AI request/response
 * and every moderation decision is recorded, so a run is fully reconstructable
 * for debugging. A configuration/integration failure is logged here too (as the
 * matching request type with a populated `error`) before the run is failed.
 *
 * Lifecycle events (PROCESSING_*, RETRY_REQUESTED, STUCK_RECONCILED) record the
 * execution itself — claim, completion, failure, ignored duplicate deliveries,
 * retries, and stuck-run reconciliation. They carry the stage in `request.stage`.
 */
enum GenerationLogType: string
{
    case SourceSearch = 'SOURCE_SEARCH';
    case SourceReviewsFetch = 'SOURCE_REVIEWS_FETCH';
    case AiAnalysisRequest = 'AI_ANALYSIS_REQUEST';
    case AiAnalysisResponse = 'AI_ANALYSIS_RESPONSE';
    case AiGenerationRequest = 'AI_GENERATION_REQUEST';
    case AiGenerationResponse = 'AI_GENERATION_RESPONSE';
    case Approval = 'APPROVAL';
    case Rejection = 'REJECTION';
    case Regeneration = 'REGENERATION';

    // ── Execution lifecycle ──────────────────────────────────────────────────
    case ProcessingStarted = 'PROCESSING_STARTED';
    case ProcessingSkipped = 'PROCESSING_SKIPPED';
    case ProcessingCompleted = 'PROCESSING_COMPLETED';
    case ProcessingFailed = 'PROCESSING_FAILED';
    case RetryRequested = 'RETRY_REQUESTED';
    case StuckReconciled = 'STUCK_RECONCILED';

    /**
     * The execution stage this event belongs to, when it is implied by the type.
     * Lifecycle events store their stage explicitly; moderation events have none.
     */
    public function impliedStage(): ?GenerationStage
    {
        return match ($this) {
            self::SourceReviewsFetch => GenerationStage::CollectingReviews,
            self::AiAnalysisRequest, self::AiAnalysisResponse => GenerationStage::AnalyzingReviews,
            self::AiGenerationRequest, self::AiGenerationResponse => GenerationStage::GeneratingReviews,
            default => null,
        };
    }
}
