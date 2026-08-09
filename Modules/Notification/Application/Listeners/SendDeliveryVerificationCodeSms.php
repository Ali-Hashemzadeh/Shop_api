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
use Modules\Shipment\Domain\Events\DeliveryVerificationCodeIssuedEvent;

/**
 * Reissued handoff code → SMS to the customer, and nothing else.
 *
 * No database channel: the customer was already told their order is on its way,
 * and a resend is a repair of that one message rather than a new event to log in
 * their inbox. Storing it would also mean writing the code down, which is exactly
 * what the hash-only design avoids — the plaintext lives in this request and in
 * the SMS, and nowhere afterwards.
 */
class SendDeliveryVerificationCodeSms implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(DeliveryVerificationCodeIssuedEvent $event): void
    {
        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->userId,
            type: NotificationType::SHIPMENT_OUT_FOR_DELIVERY->value,
            title: 'کد تحویل سفارش',
            message: 'کد تحویل سفارش شما ارسال شد.',
            // No `data` payload: an SMS-only request stores nothing, and there is
            // no safe payload to store anyway.
            channels: [NotificationChannel::SMS],
            // The same template as the original dispatch: a resend repeats that one
            // message with a fresh code, rather than saying something new.
            sms: new SmsPayloadDTO(NotificationTemplate::SHIPMENT_OUT_FOR_DELIVERY, [
                'OrderId' => $event->orderPublicCode ?? (string) $event->orderId,
                'DeliveryCode' => $event->deliveryCode,
            ]),
        ));
    }
}
