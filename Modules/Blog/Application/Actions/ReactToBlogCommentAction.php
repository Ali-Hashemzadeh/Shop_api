<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\Enums\BlogCommentReaction;
use Modules\Blog\Domain\Models\BlogComment;

/**
 * Set the reader's single reaction on a comment. Idempotent: repeating the same
 * reaction changes nothing; choosing another reaction replaces it.
 */
final class ReactToBlogCommentAction
{
    public function handle(BlogComment $comment, int $userId, BlogCommentReaction $reaction): void
    {
        DB::transaction(function () use ($comment, $userId, $reaction): void {
            $updated = DB::table('blog_comment_reactions')
                ->where('user_id', $userId)
                ->where('comment_id', $comment->id)
                ->update(['reaction' => $reaction->value]);

            if ($updated === 0) {
                DB::table('blog_comment_reactions')->insertOrIgnore([
                    'user_id' => $userId,
                    'comment_id' => $comment->id,
                    'reaction' => $reaction->value,
                    'created_at' => now(),
                ]);
            }
        });
    }
}
