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
use Modules\Shipment\Domain\Events\ShipmentPreparingStartedEvent;

/**
 * Preparation started: SMS only — an operational nudge, not something worth
 * a permanent entry in the customer's in-app list.
 */
class SendShipmentPreparingNotification implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(ShipmentPreparingStartedEvent $event): void
    {
        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->userId,
            type: NotificationType::SHIPMENT_PREPARING->value,
            title: 'آماده‌سازی سفارش',
            message: 'سفارش شما در حال آماده‌سازی است.',
            data: ['order_id' => $event->orderId],
            channels: [NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::SHIPMENT_PREPARING, ['OrderId' => $event->orderId]),
        ));
    }
}
