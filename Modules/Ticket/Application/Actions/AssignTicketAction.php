<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Ticket\Domain\Enums\TicketMessageType;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Events\TicketAssignedEvent;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * Assign (or reassign) a ticket to a support user. The caller (AssignTicketRequest)
 * has already validated that the target actually holds the `support` role — this
 * Action trusts that and never imports the User model.
 *
 * Assigning an open ticket nudges it to `in_progress`. Idempotent: assigning the
 * current assignee changes nothing and notifies nobody. Publishes
 * TicketAssignedEvent so the Notification module tells the new assignee.
 */
class AssignTicketAction
{
    public function handle(Ticket $ticket, int $assigneeUserId): Ticket
    {
        if ((int) $ticket->assigned_to === $assigneeUserId) {
            return $ticket;
        }

        DB::transaction(function () use ($ticket, $assigneeUserId): void {
            $ticket->assigned_to = $assigneeUserId;

            if ($ticket->status === TicketStatus::Open) {
                $ticket->status = TicketStatus::InProgress->value;
            }

            $ticket->save();

            $ticket->messages()->create([
                'user_id' => null,
                'message' => "Ticket assigned to support user #{$assigneeUserId}.",
                'type' => TicketMessageType::SystemMessage->value,
            ]);

            Event::dispatch(new TicketAssignedEvent(
                ticketId: (int) $ticket->id,
                ticketNumber: (string) $ticket->ticket_number,
                assigneeUserId: $assigneeUserId,
                customerUserId: (int) $ticket->user_id,
            ));
        });

        return $ticket;
    }
}
