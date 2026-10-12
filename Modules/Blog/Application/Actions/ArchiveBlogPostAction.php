<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Blog\Application\Support\BlogPostWorkflow;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Events\BlogPostArchivedEvent;
use Modules\Blog\Domain\Models\BlogPost;

/** Any non-archived status → archived. Archived posts are never public. */
final class ArchiveBlogPostAction
{
    public function __construct(
        private readonly BlogPostWorkflow $workflow,
    ) {}

    public function handle(BlogPost $post, int $actorUserId): BlogPost
    {
        return DB::transaction(function () use ($post, $actorUserId): BlogPost {
            $wasPublic = $post->fresh()?->isPubliclyVisible() ?? false;

            $archived = $this->workflow->transition($post, BlogPostStatus::Archived, BlogActivityAction::Archived, $actorUserId);

            Event::dispatch(new BlogPostArchivedEvent(
                postId: (int) $archived->id,
                postPublicCode: (string) $archived->public_code,
                slug: (string) $archived->slug,
                wasPublic: $wasPublic,
                occurredAt: now()->toIso8601String(),
            ));

            return $archived;
        });
    }
}
