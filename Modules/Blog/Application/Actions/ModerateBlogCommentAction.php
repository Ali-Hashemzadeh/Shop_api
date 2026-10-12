<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Blog\Application\Support\BlogPostStats;
use Modules\Blog\Domain\Enums\BlogCommentStatus;
use Modules\Blog\Domain\Events\BlogCommentApprovedEvent;
use Modules\Blog\Domain\Events\BlogCommentRepliedEvent;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Approve, reject, or mark a comment as spam. Nothing transitions INTO
 * `pending` here — that state is set only by create/edit.
 *
 * The first time a comment becomes visible (approved with no prior
 * `approved_at`) it raises BlogCommentApprovedEvent for its author and, for a
 * reply to someone else, BlogCommentRepliedEvent for the parent's author —
 * re-approving later never re-notifies. Repeating the same decision is a no-op.
 */
final class ModerateBlogCommentAction
{
    public function __construct(
        private readonly BlogPostStats $stats,
    ) {}

    public function handle(BlogComment $comment, BlogCommentStatus $target, int $moderatorUserId): BlogComment
    {
        return DB::transaction(function () use ($comment, $target, $moderatorUserId): BlogComment {
            /** @var BlogComment $locked */
            $locked = BlogComment::withTrashed()->whereKey($comment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $target) {
                return $locked;
            }

            $firstApproval = $target === BlogCommentStatus::Approved && $locked->approved_at === null;

            $locked->status = $target;
            if ($target === BlogCommentStatus::Approved) {
                $locked->approved_at = $locked->approved_at ?? now();
                $locked->approved_by_user_id = $moderatorUserId;
            }
            $locked->save();

            $this->stats->recountComments((int) $locked->blog_post_id);

            if ($firstApproval && ! $locked->trashed()) {
                $this->announceApproval($locked);
            }

            return $locked->fresh();
        });
    }

    private function announceApproval(BlogComment $comment): void
    {
        /** @var BlogPost|null $post */
        $post = BlogPost::withTrashed()->find($comment->blog_post_id);
        if ($post === null) {
            return;
        }

        Event::dispatch(new BlogCommentApprovedEvent(
            commentId: (int) $comment->id,
            commentPublicCode: (string) $comment->public_code,
            authorUserId: (int) $comment->user_id,
            postId: (int) $post->id,
            postSlug: (string) $post->slug,
            postTitle: (string) $post->title,
            isReply: $comment->parent_id !== null,
            occurredAt: now()->toIso8601String(),
        ));

        if ($comment->parent_id === null) {
            return;
        }

        $parent = BlogComment::withTrashed()->find($comment->parent_id);
        if ($parent === null || $parent->trashed() || (int) $parent->user_id === (int) $comment->user_id) {
            return;
        }

        Event::dispatch(new BlogCommentRepliedEvent(
            replyCommentId: (int) $comment->id,
            replyPublicCode: (string) $comment->public_code,
            parentCommentId: (int) $parent->id,
            parentPublicCode: (string) $parent->public_code,
            parentAuthorUserId: (int) $parent->user_id,
            replyAuthorUserId: (int) $comment->user_id,
            postId: (int) $post->id,
            postSlug: (string) $post->slug,
            postTitle: (string) $post->title,
        ));
    }
}
