<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/**
 * Comment moderation (approve/reject/spam/restore) and report handling.
 */
class ModerateBlogCommentsRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return ['blog.comment.moderate'];
    }
}
