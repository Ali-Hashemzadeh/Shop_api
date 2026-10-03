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
use Modules\Ticket\Domain\Events\TicketAssignedEvent;

/**
 * A ticket was assigned to a support agent: tell that agent (in-app + SMS).
 */
class SendTicketAssignedNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(TicketAssignedEvent $event): void
    {
        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->assigneeUserId,
            type: NotificationType::TICKET_ASSIGNED->value,
            title: 'تیکت جدید به شما اختصاص یافت',
            message: 'یک درخواست جدید به شما اختصاص داده شد.',
            data: [
                'ticket_id' => $event->ticketId,
                'ticket_number' => $event->ticketNumber,
            ],
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::TICKET_ASSIGNED, ['TicketNumber' => $event->ticketNumber]),
        ));
    }
}
