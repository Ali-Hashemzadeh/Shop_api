<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Enums;

/**
 * Structured reason a generation run failed, stored on `failure_code` next to
 * the human-readable (sanitized) `failure_reason`.
 *
 * Codes are assigned from exception *types* and explicit provider signals
 * (HTTP status, cURL timeout errno) plus the stage the run was in — never by
 * searching an error message for words. Anything unrecognised is UNKNOWN_ERROR.
 *
 * `isRetryable()` is a hint for the admin UI: `true` = transient, a retry may
 * succeed as-is; `false` = fix configuration/data first. It does not gate the
 * retry endpoint, which accepts any failed run whose request is reproducible.
 */
enum GenerationFailureCode: string
{
    case SourceConfigurationError = 'SOURCE_CONFIGURATION_ERROR';
    case SourceMappingMissing = 'SOURCE_MAPPING_MISSING';
    case SourceTimeout = 'SOURCE_TIMEOUT';
    case SourceRequestFailed = 'SOURCE_REQUEST_FAILED';
    case SourceNoReviews = 'SOURCE_NO_REVIEWS';
    case AiConfigurationError = 'AI_CONFIGURATION_ERROR';
    case AiTimeout = 'AI_TIMEOUT';
    case AiRateLimited = 'AI_RATE_LIMITED';
    case AiResponseInvalid = 'AI_RESPONSE_INVALID';
    case AiGenerationFailed = 'AI_GENERATION_FAILED';
    case DatabaseError = 'DATABASE_ERROR';
    case QueueProcessingError = 'QUEUE_PROCESSING_ERROR';
    case ProcessingTimeout = 'PROCESSING_TIMEOUT';
    case UnknownError = 'UNKNOWN_ERROR';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::SourceTimeout,
            self::SourceRequestFailed,
            self::AiTimeout,
            self::AiRateLimited,
            self::AiResponseInvalid,
            self::AiGenerationFailed,
            self::DatabaseError,
            self::QueueProcessingError,
            self::ProcessingTimeout => true,
            default => false,
        };
    }

    /** Safe, generic description used when no failure_reason was recorded. */
    public function description(): string
    {
        return match ($this) {
            self::SourceConfigurationError => 'The external review source is not configured or inactive.',
            self::SourceMappingMissing => 'No external product mapping exists for this product and source.',
            self::SourceTimeout => 'The external review source timed out.',
            self::SourceRequestFailed => 'The request to the external review source failed.',
            self::SourceNoReviews => 'The external review source returned no reviews for the mapped product.',
            self::AiConfigurationError => 'The AI provider or prompt is not configured.',
            self::AiTimeout => 'The AI provider timed out.',
            self::AiRateLimited => 'The AI provider rate-limited the request.',
            self::AiResponseInvalid => 'The AI provider returned an unusable response.',
            self::AiGenerationFailed => 'The AI provider request failed.',
            self::DatabaseError => 'A database error interrupted processing.',
            self::QueueProcessingError => 'The queue job failed outside the generation pipeline.',
            self::ProcessingTimeout => 'Processing exceeded its time limit or was abandoned.',
            self::UnknownError => 'An unexpected error occurred.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
