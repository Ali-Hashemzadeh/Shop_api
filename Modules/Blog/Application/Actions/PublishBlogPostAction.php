<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Blog\Application\Support\BlogPostWorkflow;
use Modules\Blog\Application\Support\BlogRevisionRecorder;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Events\BlogPostPublishedEvent;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Make a post public — the one publication path, shared by the manual publish
 * endpoint and the `blogs:publish-scheduled` command.
 *
 * Idempotent: publishing an already-live post changes nothing, records nothing,
 * and raises no event. A real publication sets:
 *   - `published_at`       now, unless the scheduler is publishing a due
 *                          schedule (then the scheduled time is kept);
 *   - `first_published_at` once, ever;
 *   - `last_published_at`  now, on every real publication.
 * It records an official revision, the `published` audit entry, and dispatches
 * BlogPostPublishedEvent inside the transaction (consumers handle it after
 * commit, so a rollback announces nothing).
 */
final class PublishBlogPostAction
{
    public function __construct(
        private readonly BlogPostWorkflow $workflow,
        private readonly BlogRevisionRecorder $revisions,
    ) {}

    public function handle(BlogPost $post, ?int $actorUserId, bool $fromSchedule = false): BlogPost
    {
        return DB::transaction(function () use ($post, $actorUserId, $fromSchedule): BlogPost {
            /** @var BlogPost $current */
            $current = BlogPost::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if ($current->status === BlogPostStatus::Published) {
                return $current; // idempotent no-op
            }

            $now = now();
            $isFirst = $current->first_published_at === null;

            $published = $this->workflow->transition(
                $current,
                BlogPostStatus::Published,
                BlogActivityAction::Published,
                $actorUserId,
                function (BlogPost $locked) use ($now, $fromSchedule): void {
                    $keepScheduledTime = $fromSchedule && $locked->published_at !== null && $locked->published_at->lessThanOrEqualTo($now);
                    if (! $keepScheduledTime) {
                        $locked->published_at = $now;
                    }
                    $locked->first_published_at ??= $now;
                    $locked->last_published_at = $now;
                },
                ['trigger' => $fromSchedule ? 'scheduled' : 'manual'],
            );

            $this->revisions->record($published, $actorUserId);

            Event::dispatch(new BlogPostPublishedEvent(
                postId: (int) $published->id,
                postPublicCode: (string) $published->public_code,
                slug: (string) $published->slug,
                title: (string) $published->title,
                categoryIds: $published->categories->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                tagIds: $published->tags->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                authorIds: $published->authors->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                isFirstPublication: $isFirst,
                publishedAt: $published->published_at->toIso8601String(),
            ));

            return $published;
        });
    }
}
