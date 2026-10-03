<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\DTOs\SmsPayloadDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationTemplate;
use Modules\Notification\Domain\Enums\NotificationType;
use Modules\Ticket\Domain\Events\TicketReplyCreatedEvent;

/**
 * A new reply landed on a ticket: notify the *other* side.
 *
 * A staff reply notifies the customer (in-app + SMS — the classic "you have a
 * new answer" alert). A customer reply notifies the assigned agent in-app only
 * (no SMS: agents work from the queue, and an unassigned ticket has no single
 * agent to text). Internal notes never raise this event, so a private note can
 * never trigger a customer alert.
 */
class SendTicketReplyNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(TicketReplyCreatedEvent $event): void
    {
        $data = [
            'ticket_id' => $event->ticketId,
            'ticket_number' => $event->ticketNumber,
        ];

        if ($event->fromStaff) {
            $this->notifications->send(new NotificationRequestDTO(
                userId: $event->customerUserId,
                type: NotificationType::TICKET_REPLY->value,
                title: 'پاسخ جدید پشتیبانی',
                message: 'پاسخ جدیدی برای درخواست شما ثبت شد.',
                data: $data,
                channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
                sms: new SmsPayloadDTO(NotificationTemplate::TICKET_REPLY, ['TicketNumber' => $event->ticketNumber]),
            ));

            return;
        }

        // Customer replied — nudge the assigned agent, if there is one.
        if ($event->assignedTo !== null) {
            $this->notifications->send(new NotificationRequestDTO(
                userId: $event->assignedTo,
                type: NotificationType::TICKET_REPLY->value,
                title: 'پاسخ جدید مشتری',
                message: "مشتری برای تیکت {$event->ticketNumber} پاسخ جدیدی ارسال کرد.",
                data: $data,
                channels: [NotificationChannel::DATABASE],
            ));
        }
    }
}
