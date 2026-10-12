<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Application\Support\BlogPostWorkflow;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogPost;

/** in_review → approved. Approval alone never makes a post public. */
final class ApproveBlogPostAction
{
    public function __construct(
        private readonly BlogPostWorkflow $workflow,
    ) {}

    public function handle(BlogPost $post, int $actorUserId): BlogPost
    {
        return $this->workflow->transition($post, BlogPostStatus::Approved, BlogActivityAction::Approved, $actorUserId);
    }
}
