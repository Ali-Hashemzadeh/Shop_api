<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Channels;

use Modules\Notification\Domain\Contracts\NotificationChannelInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Models\Notification;

/**
 * In-app channel: persists the notification row the customer reads through
 * `GET /api/v1/notifications`. It is the only channel that creates a
 * `notifications` record.
 */
class DatabaseChannel implements NotificationChannelInterface
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::DATABASE;
    }

    public function send(NotificationRequestDTO $request, ?int $notificationId = null): ?int
    {
        $notification = Notification::create([
            'user_id' => $request->userId,
            'type' => $request->type,
            'title' => $request->title,
            'message' => $request->message,
            'data' => $request->data,
        ]);

        return $notification->id;
    }
}
