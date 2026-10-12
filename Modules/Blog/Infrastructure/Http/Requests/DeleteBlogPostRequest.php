<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/** Soft delete: `blog.post.delete`. */
class DeleteBlogPostRequest extends BlogPostAbilityRequest
{
    protected function ability(): string
    {
        return 'delete';
    }
}
