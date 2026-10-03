<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * A customer closing their own ticket. Reuses the shared status-change primitive
 * so closing behaves identically whoever triggers it (system message, closed_at
 * stamp, status-changed event) — the only thing special here is the caller.
 */
class CloseTicketAction
{
    public function __construct(
        private readonly ChangeTicketStatusAction $changeStatus,
    ) {}

    public function handle(Ticket $ticket, int $actorUserId): Ticket
    {
        return $this->changeStatus->handle($ticket, TicketStatus::Closed, $actorUserId);
    }
}
