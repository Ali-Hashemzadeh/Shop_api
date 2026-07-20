<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Notification\Domain\Models\Notification;

/**
 * Notifications are strictly self-service: a user reads and marks their own.
 * Permission-based and typehinted against framework contracts — never against
 * another module's User model.
 */
class NotificationPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can('notification.view-own');
    }

    public function view(Authorizable&Authenticatable $user, Notification $notification): bool
    {
        return $user->can('notification.view-own') && $this->owns($user, $notification);
    }

    public function markRead(Authorizable&Authenticatable $user, Notification $notification): bool
    {
        return $user->can('notification.mark-read-own') && $this->owns($user, $notification);
    }

    private function owns(Authenticatable $user, Notification $notification): bool
    {
        return (int) $user->getAuthIdentifier() === (int) $notification->user_id;
    }
}
