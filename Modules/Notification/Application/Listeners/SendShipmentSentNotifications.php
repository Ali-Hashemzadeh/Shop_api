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
use Modules\Shipment\Domain\Events\ShipmentSentEvent;

/**
 * Shipment on its way: in-app + SMS. `TrackingCode` is passed only when the
 * method actually produces one (postal); local delivery has none, so the
 * parameter is omitted rather than sent empty.
 */
class SendShipmentSentNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(ShipmentSentEvent $event): void
    {
        $parameters = ['OrderId' => $event->orderId];

        if ($event->trackingCode !== null && $event->trackingCode !== '') {
            $parameters['TrackingCode'] = $event->trackingCode;
        }

        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->userId,
            type: NotificationType::SHIPMENT_SENT->value,
            title: 'ارسال سفارش',
            message: 'سفارش شما ارسال شد.',
            data: array_filter([
                'order_id' => $event->orderId,
                'tracking_code' => $event->trackingCode,
            ], static fn ($value) => $value !== null),
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::SHIPMENT_SENT, $parameters),
        ));
    }
}
