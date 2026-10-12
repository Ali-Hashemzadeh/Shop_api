<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Blog\Application\Support\BlogPostStats;
use Modules\Blog\Domain\Events\BlogPostLikedEvent;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Like (`$liked = true`) or unlike a post. Idempotent both ways: the unique
 * (user_id, blog_post_id) key decides whether anything changed, and only a real
 * change moves `likes_count` or raises BlogPostLikedEvent.
 */
final class SetBlogPostLikeAction
{
    public function __construct(
        private readonly BlogPostStats $stats,
    ) {}

    public function handle(BlogPost $post, int $userId, bool $liked): bool
    {
        DB::transaction(function () use ($post, $userId, $liked): void {
            if ($liked) {
                $inserted = DB::table('blog_post_likes')->insertOrIgnore([
                    'user_id' => $userId,
                    'blog_post_id' => $post->id,
                    'created_at' => now(),
                ]);

                if ($inserted > 0) {
                    $this->stats->increment((int) $post->id, 'likes_count');
                    Event::dispatch(new BlogPostLikedEvent((int) $post->id, $userId, now()->toIso8601String()));
                }

                return;
            }

            $deleted = DB::table('blog_post_likes')->where('user_id', $userId)->where('blog_post_id', $post->id)->delete();
            if ($deleted > 0) {
                $this->stats->decrement((int) $post->id, 'likes_count');
            }
        });

        return $liked;
    }
}
