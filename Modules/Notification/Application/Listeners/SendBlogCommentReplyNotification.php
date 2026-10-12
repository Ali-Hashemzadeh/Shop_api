<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Blog\Domain\Events\BlogCommentRepliedEvent;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationType;

/** An approved reply now sits under the reader's comment. In-app only. */
class SendBlogCommentReplyNotification implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(BlogCommentRepliedEvent $event): void
    {
        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->parentAuthorUserId,
            type: NotificationType::BLOG_COMMENT_REPLY->value,
            title: 'پاسخ جدید به دیدگاه شما',
            message: "به دیدگاه شما در «{$event->postTitle}» پاسخ داده شد.",
            data: [
                'comment_code' => $event->replyPublicCode,
                'parent_comment_code' => $event->parentPublicCode,
                'slug' => $event->postSlug,
            ],
            channels: [NotificationChannel::DATABASE],
        ));
    }
}
