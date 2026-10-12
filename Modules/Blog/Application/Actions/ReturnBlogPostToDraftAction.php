<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Application\Support\BlogPostWorkflow;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * in_review → draft (an editor sends the article back), or archived → draft
 * (reviving an archived article for rework). An optional reason is kept in the
 * audit trail.
 */
final class ReturnBlogPostToDraftAction
{
    public function __construct(
        private readonly BlogPostWorkflow $workflow,
    ) {}

    public function handle(BlogPost $post, int $actorUserId, ?string $reason = null): BlogPost
    {
        return $this->workflow->transition(
            $post,
            BlogPostStatus::Draft,
            BlogActivityAction::ReturnedToDraft,
            $actorUserId,
            metadata: $reason !== null && $reason !== '' ? ['reason' => $reason] : null,
        );
    }
}
