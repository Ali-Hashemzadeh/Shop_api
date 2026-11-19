<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use RuntimeException;
use Throwable;

/**
 * A live external marketplace call failed (network error, non-2xx, or an
 * unparseable payload). Distinct from IntegrationUnavailableException, which is
 * a configuration problem rather than a request failure.
 *
 * The thrower may attach a structured GenerationFailureCode (SOURCE_TIMEOUT,
 * SOURCE_REQUEST_FAILED, SOURCE_NO_REVIEWS).
 */
class ExternalSourceException extends RuntimeException implements HasFailureCode
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
