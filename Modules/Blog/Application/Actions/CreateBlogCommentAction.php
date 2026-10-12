<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Blog\Domain\Enums\BlogCommentStatus;
use Modules\Blog\Domain\Events\BlogCommentCreatedEvent;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Write a comment or a reply. Every new comment starts `pending` — nothing a
 * reader writes is public until a moderator approves it. A reader may write any
 * number of top-level comments (this is a discussion, not a one-per-user
 * review). Eligibility (post commentable, parent approved, depth) was validated
 * by the request; attachments were ownership-checked through the Media contract.
 */
final class CreateBlogCommentAction
{
    /** @param  list<int>  $mediaIds */
    public function handle(BlogPost $post, int $userId, string $body, array $mediaIds = [], ?BlogComment $parent = null): BlogComment
    {
        return DB::transaction(function () use ($post, $userId, $body, $mediaIds, $parent): BlogComment {
            $comment = BlogComment::createWithPublicCode([
                'blog_post_id' => $post->id,
                'user_id' => $userId,
                'parent_id' => $parent?->id,
                'body' => $body,
                'status' => BlogCommentStatus::Pending->value,
            ]);

            foreach (array_values(array_unique(array_map('intval', $mediaIds))) as $index => $mediaId) {
                DB::table('blog_comment_media')->insert([
                    'blog_comment_id' => $comment->id,
                    'media_id' => $mediaId,
                    'sort_order' => $index,
                ]);
            }

            Event::dispatch(new BlogCommentCreatedEvent(
                commentId: (int) $comment->id,
                commentPublicCode: (string) $comment->public_code,
                postId: (int) $post->id,
                postPublicCode: (string) $post->public_code,
                userId: $userId,
                parentCommentId: $parent?->id !== null ? (int) $parent->id : null,
                occurredAt: now()->toIso8601String(),
            ));

            return $comment;
        });
    }
}
