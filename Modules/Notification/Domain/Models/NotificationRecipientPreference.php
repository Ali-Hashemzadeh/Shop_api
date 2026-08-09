<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Who wants which notification, on which channel.
 *
 * Private to the Notification module. `user_id` is a loose Identity reference —
 * there is no relation to the User model here, and there never should be.
 */
class NotificationRecipientPreference extends Model
{
    protected $fillable = [
        'user_id',
        'notification_type',
        'channel',
        'enabled',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'enabled' => 'boolean',
    ];
}
