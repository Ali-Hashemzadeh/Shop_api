<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/** Publish / unpublish now: `blog.post.publish`. */
class PublishBlogPostRequest extends BlogPostAbilityRequest
{
    protected function ability(): string
    {
        return 'publish';
    }
}
