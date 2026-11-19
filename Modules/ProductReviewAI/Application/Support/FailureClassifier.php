<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use Modules\ProductReviewAI\Domain\Enums\GenerationStage;
use Modules\ProductReviewAI\Domain\Exceptions\AiGenerationException;
use Modules\ProductReviewAI\Domain\Exceptions\ExternalSourceException;
use Modules\ProductReviewAI\Domain\Exceptions\HasFailureCode;
use Modules\ProductReviewAI\Domain\Exceptions\IntegrationUnavailableException;
use Modules\ProductReviewAI\Domain\Exceptions\MappingNotFoundException;
use Modules\ProductReviewAI\Domain\Exceptions\SourceNotConfiguredException;
use Modules\ProductReviewAI\Infrastructure\Support\HttpFailure;
use PDOException;
use Throwable;

/**
 * Maps a pipeline exception to a GenerationFailureCode and a safe admin-facing
 * reason. Precedence:
 *
 *  1. an explicit code set by the thrower (HasFailureCode) — provider HTTP
 *     status, cURL timeout errno, invalid JSON, missing config;
 *  2. the exception type, disambiguated by the stage it happened in (e.g. an
 *     IntegrationUnavailableException while collecting is a *source* problem);
 *  3. UNKNOWN_ERROR.
 *
 * It never inspects message text. Full technical detail stays in the protected
 * server log; the reason returned here is sanitized and short, and database
 * errors are reduced to their SQLSTATE (their messages embed SQL + bindings).
 */
final class FailureClassifier
{
    public function __construct(
        private readonly DiagnosticSanitizer $sanitizer,
    ) {}

    public function classify(Throwable $e, ?GenerationStage $stage): GenerationFailureCode
    {
        if ($e instanceof HasFailureCode && $e->failureCode() !== null) {
            return $e->failureCode();
        }

        $isSourceStage = $stage === null
            || $stage === GenerationStage::Queued
            || $stage === GenerationStage::Initializing
            || $stage === GenerationStage::CollectingReviews;

        return match (true) {
            $e instanceof SourceNotConfiguredException => GenerationFailureCode::SourceConfigurationError,
            $e instanceof MappingNotFoundException => GenerationFailureCode::SourceMappingMissing,
            $e instanceof IntegrationUnavailableException => $isSourceStage
                ? GenerationFailureCode::SourceConfigurationError
                : GenerationFailureCode::AiConfigurationError,
            $e instanceof ExternalSourceException => HttpFailure::isTimeout($e)
                ? GenerationFailureCode::SourceTimeout
                : GenerationFailureCode::SourceRequestFailed,
            $e instanceof AiGenerationException => HttpFailure::isTimeout($e)
                ? GenerationFailureCode::AiTimeout
                : GenerationFailureCode::AiGenerationFailed,
            $e instanceof ConnectionException => $isSourceStage
                ? (HttpFailure::isTimeout($e) ? GenerationFailureCode::SourceTimeout : GenerationFailureCode::SourceRequestFailed)
                : (HttpFailure::isTimeout($e) ? GenerationFailureCode::AiTimeout : GenerationFailureCode::AiGenerationFailed),
            $e instanceof QueryException, $e instanceof PDOException => GenerationFailureCode::DatabaseError,
            default => GenerationFailureCode::UnknownError,
        };
    }

    /**
     * Short, sanitized, admin-safe reason for a failure.
     */
    public function reasonFor(Throwable $e, GenerationFailureCode $code): string
    {
        if ($code === GenerationFailureCode::DatabaseError) {
            $sqlState = $e instanceof QueryException || $e instanceof PDOException ? (string) $e->getCode() : '';

            return $code->description().($sqlState !== '' && $sqlState !== '0' ? " (SQLSTATE {$sqlState})" : '');
        }

        $message = $this->sanitizer->text($e->getMessage(), 500);

        return $message !== null && $message !== '' ? $message : $code->description();
    }
}
