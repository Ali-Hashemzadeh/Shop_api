<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Blog\Application\Support\BlogEngagementGate;
use Modules\Blog\Application\Support\BlogPostStats;
use Modules\Blog\Domain\Events\BlogProductClickedEvent;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Record a click from an article to one of its linked products (shoppable
 * content). The product must be linked to the post (validated by the request).
 * Bot-filtered and deduplicated per viewer + post + product; a counted click
 * bumps `product_clicks_count` and raises BlogProductClickedEvent.
 */
final class RecordBlogProductClickAction
{
    public function __construct(
        private readonly BlogPostStats $stats,
    ) {}

    public function handle(
        BlogPost $post,
        int $productId,
        string $viewerKey,
        ?int $userId,
        ?string $sessionHash,
        ?string $placement,
        ?string $userAgent,
    ): bool {
        if (BlogEngagementGate::isBot($userAgent)) {
            return false;
        }

        if (! BlogEngagementGate::firstWithin('click:'.$post->id.':'.$productId, $viewerKey, (int) config('blog.engagement.click_dedup_minutes', 60))) {
            return false;
        }

        DB::transaction(function () use ($post, $productId, $userId, $sessionHash, $placement): void {
            $this->stats->increment((int) $post->id, 'product_clicks_count');
            Event::dispatch(new BlogProductClickedEvent(
                postId: (int) $post->id,
                productId: $productId,
                userId: $userId,
                sessionHash: $sessionHash,
                placement: $placement,
                occurredAt: now()->toIso8601String(),
            ));
        });

        return true;
    }
}
