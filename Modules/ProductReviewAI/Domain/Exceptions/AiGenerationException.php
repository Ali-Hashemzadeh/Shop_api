<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use RuntimeException;
use Throwable;

/**
 * The AI provider returned an error, an empty completion, or output that could
 * not be parsed as the required JSON shape.
 *
 * The thrower may attach a structured GenerationFailureCode (e.g. AI_RATE_LIMITED
 * for HTTP 429, AI_TIMEOUT for a cURL timeout, AI_RESPONSE_INVALID for bad JSON).
 */
class AiGenerationException extends RuntimeException implements HasFailureCode
{
    /**
     * Backward compatible with RuntimeException's (message, code, previous).
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?GenerationFailureCode $failureCode = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function failureCode(): ?GenerationFailureCode
    {
        return $this->failureCode;
    }
}
