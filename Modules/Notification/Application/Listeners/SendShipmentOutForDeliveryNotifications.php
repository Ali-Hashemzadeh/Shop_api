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
use Modules\Shipment\Domain\Events\ShipmentOutForDeliveryEvent;

/**
 * Local delivery on the road: one in-app notification and exactly one SMS.
 *
 * One message, not two — the customer is not asked to reconcile a pair of texts
 * about the same dispatch, so the handoff code travels inside the same SMS that
 * announces it.
 *
 * The raw code fills the SMS template and is then dropped. It never enters the
 * stored notification's `data`, because a code readable from the customer's
 * in-app inbox — or by anyone with database access — would no longer prove that
 * the person at the door actually received the parcel.
 */
class SendShipmentOutForDeliveryNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(ShipmentOutForDeliveryEvent $event): void
    {
        // `OrderId`/`DeliveryCode` are the provider-side template variable names;
        // the order value is the customer-facing code, not the internal id.
        $parameters = ['OrderId' => $event->orderPublicCode ?? (string) $event->orderId];

        if ($event->deliveryCode !== null && $event->deliveryCode !== '') {
            $parameters['DeliveryCode'] = $event->deliveryCode;
        }

        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->userId,
            type: NotificationType::SHIPMENT_OUT_FOR_DELIVERY->value,
            title: 'ارسال سفارش',
            message: 'سفارش شما برای تحویل ارسال شد.',
            // Deliberately no code of any kind here — not the plaintext, not the hash.
            data: ['order_id' => $event->orderId, 'order_public_code' => $event->orderPublicCode],
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::SHIPMENT_OUT_FOR_DELIVERY, $parameters),
        ));
    }
}
