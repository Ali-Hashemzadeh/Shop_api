<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a ticket was assigned to a support user.
 *
 * The Notification module tells that support user. Primitives only.
 */
class TicketAssignedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $ticketNumber,
        public readonly int $assigneeUserId,
        public readonly int $customerUserId,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
