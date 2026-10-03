<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Ticket\Domain\Enums\TicketMessageType;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Events\TicketStatusChangedEvent;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * Move a ticket to a new status. Records a system message for the audit trail,
 * stamps/clears `closed_at`, and publishes TicketStatusChangedEvent so the
 * customer is kept informed. Idempotent: setting the current status is a no-op
 * (no message, no event).
 */
class ChangeTicketStatusAction
{
    public function handle(Ticket $ticket, TicketStatus $status, ?int $actorUserId = null): Ticket
    {
        $old = $ticket->status instanceof TicketStatus ? $ticket->status : TicketStatus::from((string) $ticket->status);

        if ($old === $status) {
            return $ticket;
        }

        DB::transaction(function () use ($ticket, $status, $actorUserId, $old): void {
            $ticket->status = $status->value;
            $ticket->closed_at = $status->isTerminal() ? now() : null;
            $ticket->save();

            $ticket->messages()->create([
                'user_id' => $actorUserId,
                'message' => "Status changed from {$old->value} to {$status->value}.",
                'type' => TicketMessageType::SystemMessage->value,
            ]);

            Event::dispatch(new TicketStatusChangedEvent(
                ticketId: (int) $ticket->id,
                ticketNumber: (string) $ticket->ticket_number,
                customerUserId: (int) $ticket->user_id,
                oldStatus: $old->value,
                newStatus: $status->value,
            ));
        });

        return $ticket;
    }
}
