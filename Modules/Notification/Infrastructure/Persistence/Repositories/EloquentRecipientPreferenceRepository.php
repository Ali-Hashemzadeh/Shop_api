<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Persistence\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationType;
use Modules\Notification\Domain\Models\NotificationRecipientPreference;

class EloquentRecipientPreferenceRepository implements RecipientPreferenceRepositoryInterface
{
    public function enabledUserIds(NotificationType $type, NotificationChannel $channel): array
    {
        return NotificationRecipientPreference::query()
            ->where('notification_type', $type->value)
            ->where('channel', $channel->value)
            ->where('enabled', true)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    public function syncEnabled(NotificationType $type, NotificationChannel $channel, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        DB::transaction(function () use ($type, $channel, $userIds): void {
            $scope = NotificationRecipientPreference::query()
                ->where('notification_type', $type->value)
                ->where('channel', $channel->value);

            // Everyone not on the new list is disabled rather than deleted: the row
            // is the record of a decision, and keeping it means an admin removed
            // later can be told apart from one who was never considered.
            (clone $scope)->whereNotIn('user_id', $userIds ?: [0])->update(['enabled' => false]);

            foreach ($userIds as $userId) {
                NotificationRecipientPreference::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'notification_type' => $type->value,
                        'channel' => $channel->value,
                    ],
                    ['enabled' => true],
                );
            }
        });
    }
}
