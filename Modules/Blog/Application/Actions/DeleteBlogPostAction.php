<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Application\Support\BlogActivityLogger;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Soft-delete a post. Revisions, the audit trail, slug history, and every
 * attachment survive untouched so an authorized restore brings the article
 * back exactly as it was. A deleted post is never public.
 */
final class DeleteBlogPostAction
{
    public function __construct(
        private readonly BlogActivityLogger $activity,
    ) {}

    public function handle(BlogPost $post, int $actorUserId): void
    {
        DB::transaction(function () use ($post, $actorUserId): void {
            $this->activity->log($post, BlogActivityAction::Deleted, $actorUserId, ['status' => $post->status->value]);
            $post->updated_by_user_id = $actorUserId;
            $post->saveQuietly();
            $post->delete();
        });
    }
}
