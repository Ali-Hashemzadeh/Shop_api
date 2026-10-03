<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationType;
use Modules\Ticket\Domain\Events\TicketStatusChangedEvent;

/**
 * A ticket's status changed: keep the customer informed (in-app only — a status
 * move is not worth an SMS).
 */
class SendTicketStatusChangedNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(TicketStatusChangedEvent $event): void
    {
        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->customerUserId,
            type: NotificationType::TICKET_STATUS_CHANGED->value,
            title: 'وضعیت تیکت تغییر کرد',
            message: "وضعیت درخواست {$event->ticketNumber} به‌روزرسانی شد.",
            data: [
                'ticket_id' => $event->ticketId,
                'ticket_number' => $event->ticketNumber,
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
            ],
            channels: [NotificationChannel::DATABASE],
        ));
    }
}
