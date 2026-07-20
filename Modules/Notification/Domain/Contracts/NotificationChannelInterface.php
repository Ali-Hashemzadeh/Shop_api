<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\Contracts;

use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;

/**
 * One delivery mechanism for a notification.
 *
 * A channel knows *how* to deliver, never *when* — the decision of which
 * channels a business event uses belongs to the caller.
 */
interface NotificationChannelInterface
{
    public function channel(): NotificationChannel;

    /**
     * Deliver the notification.
     *
     * @param  int|null  $notificationId  id of the stored in-app notification, when one
     *                                    exists (SMS-only notifications have none)
     * @return int|null the id of an in-app notification this channel created, if any
     */
    public function send(NotificationRequestDTO $request, ?int $notificationId = null): ?int;
}
