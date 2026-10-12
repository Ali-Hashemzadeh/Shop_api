<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Optimistic-lock failure: the editor saved on top of a version that is no
 * longer current. Renders 409 with the current version so the client can
 * reload and merge instead of silently overwriting a colleague's work.
 */
final class BlogPostVersionConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $submittedVersion,
        public readonly int $currentVersion,
    ) {
        parent::__construct('This post was changed by someone else. Reload it and re-apply your edits.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'submitted_version' => $this->submittedVersion,
            'current_version' => $this->currentVersion,
        ], 409);
    }
}
