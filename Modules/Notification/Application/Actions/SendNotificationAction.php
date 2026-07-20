<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Actions;

use Illuminate\Support\Facades\Log;
use Modules\Notification\Domain\DTOs\NotificationDTO;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Models\Notification;
use Modules\Notification\Infrastructure\Channels\NotificationChannelFactory;
use Throwable;

/**
 * Fans a notification request out across its channels.
 *
 * The database channel runs first so external channels can attach their
 * delivery records to the stored notification. External delivery is
 * best-effort: a failing channel is logged and recorded, never rethrown, so a
 * business flow is never rolled back because SMS was unavailable. For the same
 * reason there is no surrounding transaction — the only write that must be
 * durable is the in-app row, which is a single insert.
 */
class SendNotificationAction
{
    public function __construct(
        private readonly NotificationChannelFactory $channels,
    ) {}

    public function handle(NotificationRequestDTO $request): ?NotificationDTO
    {
        $notificationId = null;

        if ($request->hasChannel(NotificationChannel::DATABASE)) {
            $notificationId = $this->channels->make(NotificationChannel::DATABASE)->send($request);
        }

        foreach ($request->channels as $channel) {
            if ($channel === NotificationChannel::DATABASE) {
                continue;
            }

            try {
                $this->channels->make($channel)->send($request, $notificationId);
            } catch (Throwable $e) {
                Log::error('[Notification] channel dispatch failed', [
                    'channel' => $channel->value,
                    'type' => $request->type,
                    'user_id' => $request->userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($notificationId === null) {
            return null;
        }

        return NotificationDTO::fromModel(Notification::findOrFail($notificationId));
    }
}
