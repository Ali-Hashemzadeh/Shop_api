<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a ticket's status changed.
 *
 * The Notification module keeps the customer informed. Primitives only.
 */
class TicketStatusChangedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $ticketNumber,
        public readonly int $customerUserId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
