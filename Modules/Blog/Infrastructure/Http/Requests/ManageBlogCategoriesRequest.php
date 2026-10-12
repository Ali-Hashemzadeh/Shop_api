<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/**
 * Category writes/deletes.
 */
class ManageBlogCategoriesRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return ['blog.category.manage'];
    }
}
