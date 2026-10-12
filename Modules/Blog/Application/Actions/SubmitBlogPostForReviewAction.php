<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Application\Support\BlogPostWorkflow;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogPost;

/** draft → in_review. */
final class SubmitBlogPostForReviewAction
{
    public function __construct(
        private readonly BlogPostWorkflow $workflow,
    ) {}

    public function handle(BlogPost $post, int $actorUserId): BlogPost
    {
        return $this->workflow->transition($post, BlogPostStatus::InReview, BlogActivityAction::SubmittedForReview, $actorUserId);
    }
}
