<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a new (non-internal) reply landed on a ticket.
 *
 * `fromStaff` tells the listener which way to fan out — a staff reply notifies
 * the customer, a customer reply notifies the assignee. Internal notes never
 * raise this event. Primitives only.
 */
class TicketReplyCreatedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $ticketNumber,
        public readonly int $customerUserId,
        public readonly ?int $assignedTo,
        public readonly int $authorUserId,
        public readonly bool $fromStaff,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
