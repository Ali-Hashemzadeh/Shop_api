<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Actions;

use Modules\Notification\Domain\DTOs\NotificationDTO;
use Modules\Notification\Domain\Models\Notification;

/**
 * Marks one notification as read. Ownership is part of the lookup, so a
 * notification belonging to someone else can never be mutated here even if a
 * caller skips the policy check.
 */
class MarkNotificationAsReadAction
{
    public function handle(int $notificationId, int $userId): NotificationDTO
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->firstOrFail();

        // Idempotent: keep the first read timestamp.
        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return NotificationDTO::fromModel($notification);
    }
}
