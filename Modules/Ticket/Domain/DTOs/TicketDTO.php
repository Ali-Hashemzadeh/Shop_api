<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\DTOs;

use Carbon\Carbon;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * Immutable view of one ticket crossing the boundary.
 *
 * `messages` and `references` are populated only for the detail view; list
 * responses leave them null. Message visibility (hiding internal notes from
 * customers) is decided by the query that hydrates this DTO, never here.
 */
class TicketDTO
{
    /**
     * @param  list<TicketMessageDTO>|null  $messages
     * @param  list<TicketReferenceDTO>|null  $references
     */
    public function __construct(
        public readonly int $id,
        public readonly string $ticketNumber,
        public readonly int $userId,
        public readonly ?int $assignedTo,
        public readonly string $subject,
        public readonly ?string $category,
        public readonly string $priority,
        public readonly string $status,
        public readonly ?Carbon $lastMessageAt,
        public readonly ?Carbon $closedAt,
        public readonly Carbon $createdAt,
        public readonly Carbon $updatedAt,
        public readonly ?array $messages = null,
        public readonly ?array $references = null,
    ) {}

    /**
     * @param  list<TicketMessageDTO>|null  $messages
     * @param  list<TicketReferenceDTO>|null  $references
     */
    public static function fromModel(Ticket $ticket, ?array $messages = null, ?array $references = null): self
    {
        return new self(
            id: (int) $ticket->id,
            ticketNumber: (string) $ticket->ticket_number,
            userId: (int) $ticket->user_id,
            assignedTo: $ticket->assigned_to !== null ? (int) $ticket->assigned_to : null,
            subject: (string) $ticket->subject,
            category: $ticket->category,
            priority: $ticket->priority instanceof TicketPriority
                ? $ticket->priority->value
                : (string) $ticket->priority,
            status: $ticket->status instanceof TicketStatus
                ? $ticket->status->value
                : (string) $ticket->status,
            lastMessageAt: $ticket->last_message_at !== null ? Carbon::parse($ticket->last_message_at) : null,
            closedAt: $ticket->closed_at !== null ? Carbon::parse($ticket->closed_at) : null,
            createdAt: Carbon::parse($ticket->created_at),
            updatedAt: Carbon::parse($ticket->updated_at),
            messages: $messages,
            references: $references,
        );
    }
}
