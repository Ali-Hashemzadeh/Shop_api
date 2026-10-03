<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Ticket\Application\Support\ResolvesMessageAttachments;
use Modules\Ticket\Domain\DTOs\TicketMessageDTO;
use Modules\Ticket\Domain\Enums\TicketMessageType;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Events\TicketReplyCreatedEvent;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketMessage;

/**
 * Append a visible reply to a ticket. Shared by the customer, support, and admin
 * surfaces — only the caller-supplied `$fromStaff` flag (and the scoping the
 * controller already applied) differ.
 *
 * Light status automation keeps the queue meaningful without a workflow engine:
 * a staff reply marks the ticket `answered`; a customer reply on a ticket that
 * was waiting on them reopens it. Publishes TicketReplyCreatedEvent after commit
 * so the Notification module can tell the other side.
 */
class ReplyToTicketAction
{
    use ResolvesMessageAttachments;

    public function __construct(
        private readonly MediaManagerInterface $media,
    ) {}

    /**
     * @param  list<int>  $mediaIds  pre-uploaded, caller-owned attachments (validated in the request)
     */
    public function handle(Ticket $ticket, int $authorUserId, string $message, bool $fromStaff, array $mediaIds = []): TicketMessageDTO
    {
        $reply = DB::transaction(function () use ($ticket, $authorUserId, $message, $fromStaff, $mediaIds): TicketMessage {
            $reply = $ticket->messages()->create([
                'user_id' => $authorUserId,
                'message' => $message,
                'media_ids' => $mediaIds === [] ? null : array_values(array_map('intval', $mediaIds)),
                'type' => ($fromStaff ? TicketMessageType::AdminReply : TicketMessageType::CustomerReply)->value,
            ]);

            $ticket->last_message_at = now();

            if ($fromStaff) {
                $ticket->status = TicketStatus::Answered->value;
            } elseif (in_array($ticket->status, [TicketStatus::Answered, TicketStatus::WaitingForCustomer], true)) {
                // The customer came back — the ball is with support again.
                $ticket->status = TicketStatus::Open->value;
            }

            $ticket->save();

            Event::dispatch(new TicketReplyCreatedEvent(
                ticketId: (int) $ticket->id,
                ticketNumber: (string) $ticket->ticket_number,
                customerUserId: (int) $ticket->user_id,
                assignedTo: $ticket->assigned_to !== null ? (int) $ticket->assigned_to : null,
                authorUserId: $authorUserId,
                fromStaff: $fromStaff,
            ));

            return $reply;
        });

        return TicketMessageDTO::fromModel(
            $reply,
            $this->resolveMessageAttachments($this->media, $reply->media_ids),
        );
    }
}
