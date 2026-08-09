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
use Modules\Notification\Infrastructure\Persistence\Repositories\RecipientPreferenceRepositoryInterface;
use Modules\Order\Domain\Events\OrderPaidEvent;

/**
 * Payment succeeded: tell the customer (in-app + SMS) and every admin (in-app).
 *
 * Admins additionally get an SMS, but only the ones selected in the recipient
 * settings. A busy shop has more admin accounts than people who want their phone
 * buzzing at 3am for every order, and the in-app list is already complete — so
 * the SMS is opt-in per admin while the in-app notification stays universal.
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
        private readonly RecipientPreferenceRepositoryInterface $preferences,
    ) {}

    public function handle(OrderPaidEvent $event): void
    {
        // Anything a human reads quotes the public code; the numeric id stays in
        // `data` because in-app deep links still resolve orders by id. The SMS
        // parameter keeps the name `OrderId` — that name is the provider-side
        // template variable, so renaming it would break configured templates.
        $reference = $event->orderPublicCode ?? (string) $event->orderId;

        $this->notifications->send(new NotificationRequestDTO(
            userId: $event->userId,
            type: NotificationType::PAYMENT_SUCCESS->value,
            title: 'پرداخت موفق',
            message: 'پرداخت سفارش شما با موفقیت انجام شد.',
            data: ['order_id' => $event->orderId, 'order_public_code' => $event->orderPublicCode],
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::PAYMENT_SUCCESS, ['OrderId' => $reference]),
        ));

        // Selected admins go through the same per-user pipeline as everyone else,
        // so phone resolution, provider skips and delivery auditing keep working
        // exactly as they do for a customer. There is no bulk "admin phones" path.
        $smsRecipients = array_flip($this->preferences->enabledUserIds(
            NotificationType::ADMIN_ORDER_PAID,
            NotificationChannel::SMS,
        ));

        foreach ($this->identity->getAdminUserIds() as $adminId) {
            $wantsSms = isset($smsRecipients[$adminId]);

            $this->notifications->send(new NotificationRequestDTO(
                userId: $adminId,
                type: NotificationType::ADMIN_ORDER_PAID->value,
                title: 'سفارش پرداخت شد',
                message: "سفارش شماره {$reference} پرداخت شد.",
                data: ['order_id' => $event->orderId, 'order_public_code' => $event->orderPublicCode],
                channels: $wantsSms
                    ? [NotificationChannel::DATABASE, NotificationChannel::SMS]
                    : [NotificationChannel::DATABASE],
                // A template of its own: the admin message says "a new order was
                // paid", which is not what the customer's receipt says.
                sms: $wantsSms
                    ? new SmsPayloadDTO(NotificationTemplate::ADMIN_ORDER_PAID, ['OrderId' => $reference])
                    : null,
            ));
        }
    }
}
