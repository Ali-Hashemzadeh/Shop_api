<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Blog\Application\Support\BlogPostStats;
use Modules\Blog\Domain\Models\BlogComment;

/** A moderator restores a soft-deleted comment, keeping its moderation status. */
final class RestoreBlogCommentAction
{
    public function __construct(
        private readonly BlogPostStats $stats,
    ) {}

    public function handle(BlogComment $comment): BlogComment
    {
        if (! $comment->trashed()) {
            throw ValidationException::withMessages(['comment' => 'This comment is not deleted.']);
        }

        return DB::transaction(function () use ($comment): BlogComment {
            $comment->restore();
            $this->stats->recountComments((int) $comment->blog_post_id);

            return $comment->fresh();
        });
    }
}
