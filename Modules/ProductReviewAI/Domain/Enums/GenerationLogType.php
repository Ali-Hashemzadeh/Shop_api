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
}
