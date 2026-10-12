<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Blog\Application\Support\BlogPostWorkflow;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Events\BlogPostUnpublishedEvent;
use Modules\Blog\Domain\Exceptions\InvalidBlogPostTransitionException;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * published (or scheduled) → approved: take the post off the storefront while
 * keeping it ready to republish. Clears `unpublish_at` (the instruction has
 * been carried out) and raises BlogPostUnpublishedEvent when a live post went
 * dark.
 *
 * The scheduler path is idempotent: a post that is no longer published (already
 * unpublished, archived, deleted) is skipped silently instead of erroring.
 */
final class UnpublishBlogPostAction
{
    public function __construct(
        private readonly BlogPostWorkflow $workflow,
    ) {}

    public function handle(BlogPost $post, ?int $actorUserId, bool $fromSchedule = false): BlogPost
    {
        return DB::transaction(function () use ($post, $actorUserId, $fromSchedule): BlogPost {
            /** @var BlogPost $current */
            $current = BlogPost::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if ($fromSchedule && ($current->status !== BlogPostStatus::Published
                || $current->unpublish_at === null
                || $current->unpublish_at->isFuture())) {
                return $current;
            }

            if (! in_array($current->status, [BlogPostStatus::Published, BlogPostStatus::Scheduled], true)) {
                throw new InvalidBlogPostTransitionException($current->status, BlogPostStatus::Approved);
            }

            $wasLive = $current->status === BlogPostStatus::Published;

            $updated = $this->workflow->transition(
                $current,
                BlogPostStatus::Approved,
                BlogActivityAction::Unpublished,
                $actorUserId,
                function (BlogPost $locked): void {
                    $locked->unpublish_at = null;
                },
                ['trigger' => $fromSchedule ? 'scheduled' : 'manual'],
            );

            if ($wasLive) {
                Event::dispatch(new BlogPostUnpublishedEvent(
                    postId: (int) $updated->id,
                    postPublicCode: (string) $updated->public_code,
                    slug: (string) $updated->slug,
                    trigger: $fromSchedule ? 'scheduled' : 'manual',
                    occurredAt: now()->toIso8601String(),
                ));
            }

            return $updated;
        });
    }
}
