<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Blog\Application\Support\BlogPostStats;
use Modules\Blog\Domain\Events\BlogPostBookmarkedEvent;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Bookmark (`$bookmarked = true`) or remove a bookmark. Idempotent both ways;
 * only a real change moves `bookmarks_count` or raises BlogPostBookmarkedEvent.
 */
final class SetBlogPostBookmarkAction
{
    public function __construct(
        private readonly BlogPostStats $stats,
    ) {}

    public function handle(BlogPost $post, int $userId, bool $bookmarked): bool
    {
        DB::transaction(function () use ($post, $userId, $bookmarked): void {
            if ($bookmarked) {
                $inserted = DB::table('blog_post_bookmarks')->insertOrIgnore([
                    'user_id' => $userId,
                    'blog_post_id' => $post->id,
                    'created_at' => now(),
                ]);

                if ($inserted > 0) {
                    $this->stats->increment((int) $post->id, 'bookmarks_count');
                    Event::dispatch(new BlogPostBookmarkedEvent((int) $post->id, $userId, now()->toIso8601String()));
                }

                return;
            }

            $deleted = DB::table('blog_post_bookmarks')->where('user_id', $userId)->where('blog_post_id', $post->id)->delete();
            if ($deleted > 0) {
                $this->stats->decrement((int) $post->id, 'bookmarks_count');
            }
        });

        return $bookmarked;
    }
}
