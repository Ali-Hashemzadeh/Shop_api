<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/**
 * Placement writes/deletes.
 */
class ManageBlogPlacementsRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return ['blog.placement.manage'];
    }
}
