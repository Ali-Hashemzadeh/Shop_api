<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationType;
use Modules\Payment\Domain\Events\PaymentFailedEvent;

/**
 * Verification rejected the payment: in-app only, customer only. No SMS (a
 * failed attempt is not worth a paid message) and no admin notification.
 */
class SendPaymentFailedNotification implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
    ) {}

    public function handle(PaymentFailedEvent $event): void
    {
        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->userId,
            type: NotificationType::PAYMENT_FAILED->value,
            title: 'پرداخت ناموفق',
            message: 'پرداخت سفارش شما ناموفق بود.',
            data: ['order_id' => $event->orderId],
            channels: [NotificationChannel::DATABASE],
        ));
    }
}
