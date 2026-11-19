<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;

/**
 * An exception that knows the structured failure code it represents (set by the
 * thrower from an explicit signal — HTTP status, timeout errno, missing config).
 */
interface HasFailureCode
{
    public function failureCode(): ?GenerationFailureCode;
}
