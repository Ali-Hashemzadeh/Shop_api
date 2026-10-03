<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The requested source code is unknown, inactive, or has no registered driver.
 */
class SourceNotConfiguredException extends RuntimeException
{
    /** A bad/inactive source code is a client error surfaced at request time. */
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
