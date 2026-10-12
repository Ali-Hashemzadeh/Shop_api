<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Blog\Application\Support\BlogActivityLogger;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Bring back a soft-deleted post with all of its history. The post keeps the
 * status it had; if it was published it is live again immediately (it still
 * obeys published_at / unpublish_at like any other post).
 */
final class RestoreBlogPostAction
{
    public function __construct(
        private readonly BlogActivityLogger $activity,
    ) {}

    public function handle(BlogPost $post, int $actorUserId): BlogPost
    {
        if (! $post->trashed()) {
            throw ValidationException::withMessages(['post' => 'This post is not deleted.']);
        }

        return DB::transaction(function () use ($post, $actorUserId): BlogPost {
            $post->restore();
            $post->updated_by_user_id = $actorUserId;
            $post->saveQuietly();
            $this->activity->log($post, BlogActivityAction::Restored, $actorUserId);

            return $post->fresh();
        });
    }
}
