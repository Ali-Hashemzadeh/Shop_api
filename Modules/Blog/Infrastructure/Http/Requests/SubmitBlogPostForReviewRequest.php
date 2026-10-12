<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/** draft → in_review: `blog.post.submit-review` on a post the caller owns, or any post with `blog.post.update-any`. */
class SubmitBlogPostForReviewRequest extends BlogPostAbilityRequest
{
    protected function ability(): string
    {
        return 'submitReview';
    }
}
