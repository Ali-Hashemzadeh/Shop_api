<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * No external-product mapping exists for the product + source, so there is
 * nothing to collect reviews from. The admin must search and select an external
 * product first.
 */
class MappingNotFoundException extends RuntimeException
{
    /** A missing selection is a business error, not a server fault. */
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
