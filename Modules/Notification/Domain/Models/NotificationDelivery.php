<?php

namespace Modules\Notification\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for external (non in-app) delivery attempts. Never exposed
 * through the customer API — it holds provider names and raw error strings.
 */
class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_id',
        'channel',
        'status',
        'provider',
        'provider_reference',
        'sent_at',
        'failed_at',
        'error',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
