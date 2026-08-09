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
use Modules\Shipment\Domain\Events\ShipmentHandedToPostEvent;

/**
 * Postal parcel handed over: in-app + SMS, quoting the tracking number.
 *
 * Postal wording only. The customer is being told where to go looking, not that
 * somebody is about to knock — and there is no handoff code anywhere in this
 * flow, so the local-delivery template must never be reached from here.
 */
class SendShipmentHandedToPostNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(ShipmentHandedToPostEvent $event): void
    {
        // `OrderId`/`TrackingCode` are the provider-side template variable names;
        // the order value is the customer-facing code, not the internal id.
        $parameters = ['OrderId' => $event->orderPublicCode ?? (string) $event->orderId];

        if ($event->trackingCode !== null && $event->trackingCode !== '') {
            $parameters['TrackingCode'] = $event->trackingCode;
        }

        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->userId,
            type: NotificationType::SHIPMENT_HANDED_TO_POST->value,
            title: 'تحویل به پست',
            message: 'سفارش شما به شرکت پست تحویل داده شد.',
            data: array_filter([
                'order_id' => $event->orderId,
                'order_public_code' => $event->orderPublicCode,
                'tracking_code' => $event->trackingCode,
            ], static fn ($value) => $value !== null),
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::SHIPMENT_HANDED_TO_POST, $parameters),
        ));
    }
}
