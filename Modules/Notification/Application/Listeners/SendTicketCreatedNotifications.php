<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\DTOs\SmsPayloadDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationTemplate;
use Modules\Notification\Domain\Enums\NotificationType;
use Modules\Ticket\Domain\Events\TicketCreatedEvent;

/**
 * A new ticket was opened: alert the support team (in-app + best-effort SMS).
 *
 * The audience is every support agent — the ticket is not assigned yet, so
 * everyone who can pick it up should know. Delivered through the same per-user
 * pipeline as any other notification; Ticket knows nothing about this.
 */
class SendTicketCreatedNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
        private readonly IdentityManagerInterface $identity,
    ) {}

    public function handle(TicketCreatedEvent $event): void
    {
        foreach ($this->identity->getSupportUserIds() as $supportUserId) {
            $this->notifications->send(new NotificationRequestDTO(
                userId: $supportUserId,
                type: NotificationType::TICKET_CREATED->value,
                title: 'تیکت جدید',
                message: "یک درخواست پشتیبانی جدید ({$event->ticketNumber}) ثبت شد.",
                data: [
                    'ticket_id' => $event->ticketId,
                    'ticket_number' => $event->ticketNumber,
                    'priority' => $event->priority,
                    'category' => $event->category,
                ],
                channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
                sms: new SmsPayloadDTO(NotificationTemplate::TICKET_CREATED, ['TicketNumber' => $event->ticketNumber]),
            ));
        }
    }
}
