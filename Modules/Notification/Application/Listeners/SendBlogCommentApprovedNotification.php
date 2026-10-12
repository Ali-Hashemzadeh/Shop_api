<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Blog\Domain\Events\BlogCommentApprovedEvent;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationType;

/** The reader's comment passed moderation and is now public. In-app only. */
class SendBlogCommentApprovedNotification implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(BlogCommentApprovedEvent $event): void
    {
        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->authorUserId,
            type: NotificationType::BLOG_COMMENT_APPROVED->value,
            title: 'دیدگاه شما منتشر شد',
            message: "دیدگاه شما در «{$event->postTitle}» تأیید و منتشر شد.",
            data: [
                'comment_code' => $event->commentPublicCode,
                'slug' => $event->postSlug,
            ],
            channels: [NotificationChannel::DATABASE],
        ));
    }
}
