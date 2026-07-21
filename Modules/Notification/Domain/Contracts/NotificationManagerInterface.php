<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Notification\Domain\DTOs\NotificationDTO;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;

/**
 * The Notification module's public surface. Other modules depend on this
 * interface only — never on the Notification models, channels, or the Sms
 * module underneath it.
 *
 * This contract carries no business copy: callers supply the type, title,
 * message, data payload, and channel list for their own event.
 */
interface NotificationManagerInterface
{
    /**
     * Persist (when the database channel is requested) and dispatch the
     * notification across every requested channel.
     *
     * @return NotificationDTO|null the stored in-app notification, or null when the
     *                              request targeted external channels only
     */
    public function send(NotificationRequestDTO $request): ?NotificationDTO;

    /** @return LengthAwarePaginator<int, NotificationDTO> newest first */
    public function getUserNotifications(int $userId, int $perPage = 15): LengthAwarePaginator;

    /** Idempotent: re-reading an already-read notification keeps the original timestamp. */
    public function markAsRead(int $notificationId, int $userId): NotificationDTO;

    public function unreadCount(int $userId): int;
}
