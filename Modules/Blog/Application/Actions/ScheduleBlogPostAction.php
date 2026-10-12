<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Carbon\CarbonInterface;
use Modules\Blog\Application\Support\BlogPostWorkflow;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * approved → scheduled for a future `published_at` (validated to be in the
 * future by the request). The post stays private until `blogs:publish-scheduled`
 * publishes it through the same PublishBlogPostAction a manual publish uses.
 */
final class ScheduleBlogPostAction
{
    public function __construct(
        private readonly BlogPostWorkflow $workflow,
    ) {}

    public function handle(BlogPost $post, CarbonInterface $publishAt, int $actorUserId, ?CarbonInterface $unpublishAt = null): BlogPost
    {
        return $this->workflow->transition(
            $post,
            BlogPostStatus::Scheduled,
            BlogActivityAction::Scheduled,
            $actorUserId,
            function (BlogPost $locked) use ($publishAt, $unpublishAt): void {
                $locked->published_at = $publishAt;
                if ($unpublishAt !== null) {
                    $locked->unpublish_at = $unpublishAt;
                }
            },
            ['published_at' => $publishAt->toIso8601String()],
        );
    }
}
