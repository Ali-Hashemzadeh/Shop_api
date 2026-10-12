<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Support;

use Modules\Blog\Domain\Models\BlogComment;

/**
 * Thread geometry. A top-level comment has depth 1; replies add one level. The
 * walk follows `parent_id` including soft-deleted ancestors (thread integrity)
 * and is bounded by the configured max depth, so it costs at most that many
 * primary-key lookups.
 */
final class BlogCommentThreading
{
    public static function maxDepth(): int
    {
        return max(1, (int) config('blog.comments.max_depth', 3));
    }

    public static function depthOf(BlogComment $comment): int
    {
        $depth = 1;
        $parentId = $comment->parent_id;
        $guard = self::maxDepth() + 2;

        while ($parentId !== null && $depth <= $guard) {
            $depth++;
            $parentId = BlogComment::withTrashed()->whereKey($parentId)->value('parent_id');
        }

        return $depth;
    }

    /** Can a reply be attached under this comment without exceeding max depth? */
    public static function acceptsReplies(BlogComment $parent): bool
    {
        return self::depthOf($parent) < self::maxDepth();
    }
}
