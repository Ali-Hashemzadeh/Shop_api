<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/**
 * Tag writes/deletes.
 */
class ManageBlogTagsRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return ['blog.tag.manage'];
    }
}
