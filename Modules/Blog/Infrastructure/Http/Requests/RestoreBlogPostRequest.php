<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/** Restore a soft-deleted post: `blog.post.restore`. */
class RestoreBlogPostRequest extends BlogPostAbilityRequest
{
    protected function ability(): string
    {
        return 'restore';
    }

    protected function includeTrashed(): bool
    {
        return true;
    }
}
