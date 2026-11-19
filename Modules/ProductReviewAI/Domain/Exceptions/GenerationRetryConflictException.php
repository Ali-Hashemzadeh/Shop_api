<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A manual retry cannot be started. Always a 409 — the request is well-formed,
 * the generation's current state forbids it. `reason` is a stable machine code:
 *
 *   GENERATION_NOT_FAILED      — only `failed` runs can be retried
 *   RETRY_ALREADY_EXISTS       — this run was already retried (retry the latest
 *                                attempt instead); `retry_generation_id` names it
 *   RETRY_CONTEXT_UNAVAILABLE  — the original request cannot be reproduced exactly
 *                                (historical run without a snapshot, or its source,
 *                                mapping, prompt version or provider has changed)
 */
final class GenerationRetryConflictException extends RuntimeException
{
    public const NOT_FAILED = 'GENERATION_NOT_FAILED';

    public const ALREADY_RETRIED = 'RETRY_ALREADY_EXISTS';

    public const CONTEXT_UNAVAILABLE = 'RETRY_CONTEXT_UNAVAILABLE';

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $this->getMessage(),
            'reason' => $this->reason,
        ], $this->extra), 409);
    }
}
