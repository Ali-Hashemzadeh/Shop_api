<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * The scheduler's unit of work (`blogs:publish-scheduled`, every minute):
 *
 *  1. publish every `scheduled` post whose `published_at` is due;
 *  2. unpublish every `published` post whose `unpublish_at` is due.
 *
 * Each post goes through the ordinary Actions, each in its own locked
 * transaction that re-checks the state, so overlapping runs, retries, and
 * concurrent manual edits are all idempotent — nothing is published or
 * unpublished twice and one failure does not stop the batch.
 */
final class ProcessScheduledBlogPostsAction
{
    public function __construct(
        private readonly PublishBlogPostAction $publish,
        private readonly UnpublishBlogPostAction $unpublish,
    ) {}

    /** @return array{published: int, unpublished: int} */
    public function handle(): array
    {
        $now = now();
        $published = 0;
        $unpublished = 0;

        BlogPost::query()
            ->where('status', BlogPostStatus::Scheduled->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now)
            ->orderBy('published_at')
            ->each(function (BlogPost $post) use (&$published): void {
                $before = $post->status;
                $result = $this->publish->handle($post, null, fromSchedule: true);
                if ($before !== BlogPostStatus::Published && $result->status === BlogPostStatus::Published) {
                    $published++;
                }
            });

        BlogPost::query()
            ->where('status', BlogPostStatus::Published->value)
            ->whereNotNull('unpublish_at')
            ->where('unpublish_at', '<=', $now)
            ->orderBy('unpublish_at')
            ->each(function (BlogPost $post) use (&$unpublished): void {
                $result = $this->unpublish->handle($post, null, fromSchedule: true);
                if ($result->status !== BlogPostStatus::Published) {
                    $unpublished++;
                }
            });

        return ['published' => $published, 'unpublished' => $unpublished];
    }
}
