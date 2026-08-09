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
use Modules\Shipment\Domain\Events\ShipmentReadyForPickupEvent;

/**
 * Pickup order is on the shelf: in-app + SMS.
 *
 * This is the one message a pickup customer actually needs, because nothing will
 * arrive at their door to remind them. `picked_up` deliberately stays silent.
 */
class SendShipmentReadyForPickupNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(ShipmentReadyForPickupEvent $event): void
    {
        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->userId,
            type: NotificationType::SHIPMENT_READY_FOR_PICKUP->value,
            title: 'آماده تحویل حضوری',
            message: 'سفارش شما آماده تحویل حضوری است.',
            data: ['order_id' => $event->orderId, 'order_public_code' => $event->orderPublicCode],
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            // `OrderId` is the provider-side template variable name; its value is
            // the customer-facing code, not the internal id.
            sms: new SmsPayloadDTO(NotificationTemplate::SHIPMENT_READY_FOR_PICKUP, [
                'OrderId' => $event->orderPublicCode ?? (string) $event->orderId,
            ]),
        ));
    }
}
