<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Analytics\Application\Actions\RecordBlogEngagementAction;
use Modules\Blog\Domain\Events\BlogCommentApprovedEvent;
use Modules\Blog\Domain\Events\BlogPostBookmarkedEvent;
use Modules\Blog\Domain\Events\BlogPostLikedEvent;
use Modules\Blog\Domain\Events\BlogPostViewedEvent;
use Modules\Blog\Domain\Events\BlogProductClickedEvent;

/**
 * Consumes Blog's published, primitives-only integration events. Comments are
 * counted when they become visible (approved), not when submitted, so spam that
 * never passes moderation never inflates engagement.
 */
class RecordBlogEngagementAnalytics implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly RecordBlogEngagementAction $action,
    ) {}

    public function handleViewed(BlogPostViewedEvent $event): void
    {
        $this->action->handle($event->eventId, BlogPostViewedEvent::class, $event->postId, 'views', $event->occurredAt);
    }

    public function handleLiked(BlogPostLikedEvent $event): void
    {
        $this->action->handle($event->eventId, BlogPostLikedEvent::class, $event->postId, 'likes', $event->occurredAt);
    }

    public function handleBookmarked(BlogPostBookmarkedEvent $event): void
    {
        $this->action->handle($event->eventId, BlogPostBookmarkedEvent::class, $event->postId, 'bookmarks', $event->occurredAt);
    }

    public function handleCommentApproved(BlogCommentApprovedEvent $event): void
    {
        $this->action->handle($event->eventId, BlogCommentApprovedEvent::class, $event->postId, 'comments', $event->occurredAt);
    }

    public function handleProductClicked(BlogProductClickedEvent $event): void
    {
        $this->action->handle($event->eventId, BlogProductClickedEvent::class, $event->postId, 'product_clicks', $event->occurredAt);
    }
}
