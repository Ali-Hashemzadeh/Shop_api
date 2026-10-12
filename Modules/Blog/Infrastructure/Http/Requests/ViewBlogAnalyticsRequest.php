<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/**
 * Blog engagement reporting.
 */
class ViewBlogAnalyticsRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return ['blog.analytics.view'];
    }
}
