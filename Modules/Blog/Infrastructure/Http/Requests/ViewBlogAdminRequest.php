<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/**
 * Read-only admin access to Blog posts (detail, activity, revisions).
 */
class ViewBlogAdminRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return ['blog.view-admin'];
    }
}
