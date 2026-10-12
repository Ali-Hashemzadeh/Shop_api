<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Application\Support\BlogPostStats;
use Modules\Blog\Domain\Models\BlogComment;

/**
 * Soft-delete a comment (by its author or a moderator). Replies stay attached:
 * public threads render a deleted parent as a placeholder so the conversation
 * below it keeps its shape.
 */
final class DeleteBlogCommentAction
{
    public function __construct(
        private readonly BlogPostStats $stats,
    ) {}

    public function handle(BlogComment $comment): void
    {
        DB::transaction(function () use ($comment): void {
            $comment->delete();
            $this->stats->recountComments((int) $comment->blog_post_id);
        });
    }
}
