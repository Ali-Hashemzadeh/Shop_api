<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Support;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Exceptions\InvalidBlogPostTransitionException;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * The single primitive every workflow Action goes through: lock the row,
 * assert the move against {@see BlogPostStatus::allowedTransitions()}, apply
 * it, and append the audit entry — all in one transaction. Actions add only
 * their own side effects (timestamps, revisions, integration events).
 */
final class BlogPostWorkflow
{
    public function __construct(
        private readonly BlogActivityLogger $activity,
    ) {}

    /**
     * @param  callable(BlogPost): void|null  $mutate  extra column changes applied under the same lock
     * @param  array<string, mixed>|null  $metadata
     */
    public function transition(
        BlogPost $post,
        BlogPostStatus $target,
        BlogActivityAction $action,
        ?int $actorUserId,
        ?callable $mutate = null,
        ?array $metadata = null,
    ): BlogPost {
        return DB::transaction(function () use ($post, $target, $action, $actorUserId, $mutate, $metadata): BlogPost {
            /** @var BlogPost $locked */
            $locked = BlogPost::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($target)) {
                throw new InvalidBlogPostTransitionException($locked->status, $target);
            }

            $from = $locked->status;
            $locked->status = $target;
            $locked->updated_by_user_id = $actorUserId ?? $locked->updated_by_user_id;

            if ($mutate !== null) {
                $mutate($locked);
            }

            $locked->save();

            $this->activity->log($locked, $action, $actorUserId, [
                'from' => $from->value,
                'to' => $target->value,
                ...($metadata ?? []),
            ]);

            return $locked;
        });
    }
}
