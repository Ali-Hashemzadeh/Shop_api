<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\Models\BlogComment;

/** Remove the reader's reaction. Idempotent — removing nothing is fine. */
final class RemoveBlogCommentReactionAction
{
    public function handle(BlogComment $comment, int $userId): void
    {
        DB::table('blog_comment_reactions')
            ->where('user_id', $userId)
            ->where('comment_id', $comment->id)
            ->delete();
    }
}
