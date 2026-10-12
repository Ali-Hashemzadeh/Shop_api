<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use RuntimeException;

/**
 * A workflow move the status map does not allow (e.g. publishing a draft that
 * never passed review). Renders as a standard 422 on `status`.
 */
final class InvalidBlogPostTransitionException extends RuntimeException
{
    public function __construct(
        public readonly BlogPostStatus $from,
        public readonly BlogPostStatus $to,
    ) {
        parent::__construct(sprintf('A post cannot move from "%s" to "%s".', $from->value, $to->value));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['status' => [$this->getMessage()]],
        ], 422);
    }
}
