<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Notification\Application\Actions\MarkNotificationAsReadAction;
use Modules\Notification\Application\Actions\SendNotificationAction;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\DTOs\NotificationDTO;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\Models\Notification;

class EloquentNotificationManager implements NotificationManagerInterface
{
    public function __construct(
        private readonly SendNotificationAction $sendNotification,
        private readonly MarkNotificationAsReadAction $markNotificationAsRead,
    ) {}

    public function send(NotificationRequestDTO $request): ?NotificationDTO
    {
        return $this->sendNotification->handle($request);
    }

    public function getUserNotifications(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return Notification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (Notification $notification) => NotificationDTO::fromModel($notification));
    }

    public function markAsRead(int $notificationId, int $userId): NotificationDTO
    {
        return $this->markNotificationAsRead->handle($notificationId, $userId);
    }

    public function unreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)->whereNull('read_at')->count();
    }
}
