<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/** in_review → approved: `blog.post.approve`. */
class ApproveBlogPostRequest extends BlogPostAbilityRequest
{
    protected function ability(): string
    {
        return 'approve';
    }
}
