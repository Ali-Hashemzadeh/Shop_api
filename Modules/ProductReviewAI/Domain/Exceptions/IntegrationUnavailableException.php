<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use RuntimeException;
use Throwable;

/**
 * Thrown when a required external integration is not configured (e.g. a missing
 * AVALAI_API_KEY or an empty Digikala base URL).
 *
 * The module never falls back to fake data or silently degrades: the workflow
 * stops, the failure is written to the generation log, and the admin sees that
 * the integration is unavailable. The thrower attaches SOURCE_/AI_CONFIGURATION_ERROR
 * when it knows which side is misconfigured; otherwise the stage decides.
 */
class IntegrationUnavailableException extends RuntimeException implements HasFailureCode
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
