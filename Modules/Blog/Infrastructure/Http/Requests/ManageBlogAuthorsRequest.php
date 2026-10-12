<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/**
 * Author profile writes/deletes.
 */
class ManageBlogAuthorsRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return ['blog.author.manage'];
    }
}
