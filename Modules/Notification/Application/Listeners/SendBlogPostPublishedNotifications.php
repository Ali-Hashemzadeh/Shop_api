<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Blog\Domain\Contracts\BlogManagerInterface;
use Modules\Blog\Domain\Events\BlogPostPublishedEvent;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationType;

/**
 * A post went live for the first time: tell everyone following one of its
 * categories, tags, or authors — once per reader, in-app only (a new article is
 * not worth an SMS). Followers are resolved through BlogManagerInterface; this
 * module never reads Blog tables. Republishing after an unpublish is silent.
 */
class SendBlogPostPublishedNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
        private readonly BlogManagerInterface $blog,
    ) {}

    public function handle(BlogPostPublishedEvent $event): void
    {
        if (! $event->isFirstPublication) {
            return;
        }

        $recipients = $this->blog->getSubscriberUserIds($event->categoryIds, $event->tagIds, $event->authorIds);

        foreach ($recipients as $userId) {
            $this->notifications->send(new NotificationRequestDTO(
                userId: $userId,
                type: NotificationType::BLOG_POST_PUBLISHED->value,
                title: 'مقاله جدید',
                message: "مقاله «{$event->title}» منتشر شد.",
                data: [
                    'post_code' => $event->postPublicCode,
                    'slug' => $event->slug,
                ],
                channels: [NotificationChannel::DATABASE],
            ));
        }
    }
}
