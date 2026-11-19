<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * The generation row was committed but pushing its job onto the queue threw.
 * The run has already been marked `failed` (QUEUE_PROCESSING_ERROR) so it is
 * never left as an orphaned `pending` record; it can be retried. Renders 503.
 */
final class GenerationDispatchException extends RuntimeException
{
    public function __construct(
        public readonly int $generationId,
        ?Throwable $previous = null,
    ) {
        parent::__construct('The generation was created but could not be queued. It was marked failed and can be retried.', 0, $previous);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'generation_id' => $this->generationId,
        ], 503);
    }
}
