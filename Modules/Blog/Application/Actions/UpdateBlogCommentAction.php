<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Application\Support\BlogPostStats;
use Modules\Blog\Domain\Enums\BlogCommentStatus;
use Modules\Blog\Domain\Models\BlogComment;

/**
 * The author edits their own comment. Edited content is unmoderated content: an
 * approved comment goes back to `pending` (and leaves the public thread) until a
 * moderator approves the new text. `media_ids`, when sent, replaces the
 * attachments.
 */
final class UpdateBlogCommentAction
{
    public function __construct(
        private readonly BlogPostStats $stats,
    ) {}

    /** @param  list<int>|null  $mediaIds */
    public function handle(BlogComment $comment, string $body, ?array $mediaIds = null): BlogComment
    {
        return DB::transaction(function () use ($comment, $body, $mediaIds): BlogComment {
            $wasApproved = $comment->status === BlogCommentStatus::Approved;

            $comment->body = $body;
            $comment->edited_at = now();
            if ($comment->status !== BlogCommentStatus::Spam) {
                $comment->status = BlogCommentStatus::Pending;
            }
            $comment->save();

            if ($mediaIds !== null) {
                DB::table('blog_comment_media')->where('blog_comment_id', $comment->id)->delete();
                foreach (array_values(array_unique(array_map('intval', $mediaIds))) as $index => $mediaId) {
                    DB::table('blog_comment_media')->insert([
                        'blog_comment_id' => $comment->id,
                        'media_id' => $mediaId,
                        'sort_order' => $index,
                    ]);
                }
            }

            if ($wasApproved) {
                $this->stats->recountComments((int) $comment->blog_post_id);
            }

            return $comment->fresh();
        });
    }
}
