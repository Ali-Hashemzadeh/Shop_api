<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\Enums;

/**
 * Delivery channels the module supports. Email and push are intentionally
 * absent — add a case here plus a matching NotificationChannelInterface
 * implementation when one is actually needed.
 */
enum NotificationChannel: string
{
    /** Persists an in-app notification row the customer can read via the API. */
    case DATABASE = 'database';

    /** Sends a templated SMS through the Sms module. */
    case SMS = 'sms';
}
