<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/**
 * Collection writes/deletes.
 */
class ManageBlogCollectionsRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return ['blog.collection.manage'];
    }
}
