<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/**
 * Moderator deletion of any comment.
 */
class DeleteBlogCommentAdminRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return ['blog.comment.delete'];
    }
}
