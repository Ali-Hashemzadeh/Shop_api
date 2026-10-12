<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Support;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\Enums\BlogCommentStatus;
use Modules\Blog\Domain\Models\BlogPostStat;

/**
 * Maintains the `blog_post_stats` read model from inside Blog's own write
 * paths. Counters move only on a real state change (a new like row, a removed
 * bookmark, a moderation decision) and comment counts are *recounted* from the
 * source rows rather than nudged, so they cannot drift.
 */
final class BlogPostStats
{
    private const COUNTERS = ['views_count', 'likes_count', 'bookmarks_count', 'comments_count', 'product_clicks_count'];

    public function ensure(int $postId): void
    {
        BlogPostStat::query()->firstOrCreate(['blog_post_id' => $postId]);
    }

    public function increment(int $postId, string $counter, int $by = 1): void
    {
        $this->guard($counter);
        $this->ensure($postId);

        BlogPostStat::query()->whereKey($postId)->increment($counter, $by, ['updated_at' => now()]);
    }

    public function decrement(int $postId, string $counter, int $by = 1): void
    {
        $this->guard($counter);
        $this->ensure($postId);

        // Never below zero, even if a counter was reset by hand.
        BlogPostStat::query()->whereKey($postId)->where($counter, '>=', $by)->decrement($counter, $by, ['updated_at' => now()]);
    }

    /** Visible comments = approved and not deleted. */
    public function recountComments(int $postId): void
    {
        $this->ensure($postId);

        $count = DB::table('blog_comments')
            ->where('blog_post_id', $postId)
            ->where('status', BlogCommentStatus::Approved->value)
            ->whereNull('deleted_at')
            ->count();

        BlogPostStat::query()->whereKey($postId)->update(['comments_count' => $count, 'updated_at' => now()]);
    }

    private function guard(string $counter): void
    {
        if (! in_array($counter, self::COUNTERS, true)) {
            throw new \InvalidArgumentException("Unknown blog stat counter [{$counter}].");
        }
    }
}
