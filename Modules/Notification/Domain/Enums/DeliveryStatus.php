<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\Enums;

/** Lifecycle of a single external delivery attempt. */
enum DeliveryStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';

    /**
     * Nothing was attempted because the channel is not configured for this
     * notification (e.g. no provider template id). Distinct from FAILED so an
     * unconfigured template never looks like a delivery incident.
     */
    case SKIPPED = 'skipped';
}
