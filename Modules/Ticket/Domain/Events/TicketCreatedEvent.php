<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a customer opened a new ticket.
 *
 * Carries primitives only — no Eloquent model crosses the module wall. The
 * Notification module listens for it and tells the support team; Ticket knows
 * nothing about notifications.
 */
class TicketCreatedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $ticketNumber,
        public readonly int $customerUserId,
        public readonly string $subject,
        public readonly string $priority,
        public readonly ?string $category = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
