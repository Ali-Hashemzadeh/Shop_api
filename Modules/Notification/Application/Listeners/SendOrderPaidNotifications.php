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
use Modules\Order\Domain\Events\OrderPaidEvent;

/**
 * Payment succeeded: tell the customer (in-app + SMS) and every admin (in-app).
 *
 * ShouldHandleEventsAfterCommit: the event is dispatched inside the markAsPaid
 * transaction, so this runs only once that transaction — and any outer one
 * around it — has committed. A rolled-back payment notifies nobody.
 */
class SendOrderPaidNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
        private readonly IdentityManagerInterface $identity,
    ) {}

    public function handle(OrderPaidEvent $event): void
    {
        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->userId,
            type: NotificationType::PAYMENT_SUCCESS->value,
            title: 'پرداخت موفق',
            message: 'پرداخت سفارش شما با موفقیت انجام شد.',
            data: ['order_id' => $event->orderId],
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::PAYMENT_SUCCESS, ['OrderId' => $event->orderId]),
        ));

        foreach ($this->identity->getAdminUserIds() as $adminId) {
            $this->notifications->send(new NotificationRequestDTO(
                userId: $adminId,
                type: NotificationType::ADMIN_ORDER_PAID->value,
                title: 'سفارش پرداخت شد',
                message: "سفارش شماره {$event->orderId} پرداخت شد.",
                data: ['order_id' => $event->orderId],
                channels: [NotificationChannel::DATABASE],
            ));
        }
    }
}
