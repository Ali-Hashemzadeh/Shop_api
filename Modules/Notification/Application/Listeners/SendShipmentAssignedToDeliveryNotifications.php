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
use Modules\Shipment\Domain\Events\ShipmentAssignedToDeliveryEvent;

/**
 * A delivery landed on a courier's plate: in-app so it appears in their queue,
 * and SMS because a courier is out on the road and not watching a list.
 *
 * The recipient is the delivery worker, never the customer. What the message
 * carries is deliberately thin — the shipment code, the order code, and the slot
 * — because everything operational (address, phone, map pin) sits behind the
 * authenticated driver API. In particular the customer's full address never goes
 * into an SMS, and the customer's handoff code is not here at all: a courier who
 * could read it would not need the customer to hand it over.
 */
class SendShipmentAssignedToDeliveryNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(ShipmentAssignedToDeliveryEvent $event): void
    {
        $orderReference = $event->orderPublicCode ?? (string) $event->orderId;

        $parameters = [
            'ShipmentId' => $event->shipmentPublicCode,
            'OrderId' => $orderReference,
        ];

        if ($event->deliveryDate !== null) {
            $parameters['DeliveryDate'] = $event->deliveryDate;
        }

        if ($event->deliveryStartsAt !== null) {
            $parameters['DeliveryTime'] = $event->deliveryEndsAt !== null
                ? $event->deliveryStartsAt.'-'.$event->deliveryEndsAt
                : $event->deliveryStartsAt;
        }

        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->deliveryUserId,
            type: NotificationType::SHIPMENT_ASSIGNED_DELIVERY->value,
            title: 'مأموریت ارسال جدید',
            message: 'یک مرسوله برای ارسال به شما اختصاص داده شد.',
            data: array_filter([
                'shipment_id' => $event->shipmentId,
                'shipment_public_code' => $event->shipmentPublicCode,
                'order_public_code' => $event->orderPublicCode,
                'delivery_date' => $event->deliveryDate,
                'delivery_starts_at' => $event->deliveryStartsAt,
                'delivery_ends_at' => $event->deliveryEndsAt,
            ], static fn ($value) => $value !== null),
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::SHIPMENT_ASSIGNED_DELIVERY, $parameters),
        ));
    }
}
