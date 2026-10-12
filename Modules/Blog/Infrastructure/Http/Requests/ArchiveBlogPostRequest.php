<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/** Archive: `blog.post.archive`. */
class ArchiveBlogPostRequest extends BlogPostAbilityRequest
{
    protected function ability(): string
    {
        return 'archive';
    }
}
