<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Persistence\Repositories;

use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationType;

/**
 * Internal to the Notification module — not a cross-module contract. Nothing
 * outside this module reads or writes recipient preferences; other modules
 * publish events and let Notification decide who hears about them.
 */
interface RecipientPreferenceRepositoryInterface
{
    /**
     * Ids of the users who opted in to this type on this channel.
     *
     * Absence of a row means "not selected": the paid-order SMS list starts empty
     * and stays empty until an admin picks somebody, so upgrading a live shop
     * never starts texting people who never asked for it.
     *
     * @return list<int>
     */
    public function enabledUserIds(NotificationType $type, NotificationChannel $channel): array;

    /**
     * Make the enabled set for this type/channel exactly `$userIds`.
     *
     * Idempotent and transactional: submitting the same list twice is a no-op,
     * and a partially applied list can never be observed.
     *
     * @param  list<int>  $userIds
     */
    public function syncEnabled(NotificationType $type, NotificationChannel $channel, array $userIds): void;
}
