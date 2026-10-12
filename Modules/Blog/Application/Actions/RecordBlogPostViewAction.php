<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Blog\Application\Support\BlogEngagementGate;
use Modules\Blog\Application\Support\BlogPostStats;
use Modules\Blog\Domain\Events\BlogPostViewedEvent;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Count one article view — deliberately an explicit POST the storefront sends
 * once per page view, never a side effect of GET (SSR, prefetch and crawlers
 * hit GETs). Bots are ignored and each viewer counts once per dedup window, so
 * refreshes and retries never inflate `views_count`.
 */
final class RecordBlogPostViewAction
{
    public function __construct(
        private readonly BlogPostStats $stats,
    ) {}

    public function handle(BlogPost $post, string $viewerKey, ?int $userId, ?string $userAgent): bool
    {
        if (BlogEngagementGate::isBot($userAgent)) {
            return false;
        }

        if (! BlogEngagementGate::firstWithin('view:'.$post->id, $viewerKey, (int) config('blog.engagement.view_dedup_minutes', 1440))) {
            return false;
        }

        DB::transaction(function () use ($post, $userId): void {
            $this->stats->increment((int) $post->id, 'views_count');
            Event::dispatch(new BlogPostViewedEvent((int) $post->id, $userId, now()->toIso8601String()));
        });

        return true;
    }
}
