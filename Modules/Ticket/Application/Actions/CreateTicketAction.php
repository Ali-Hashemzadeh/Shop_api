<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Ticket\Domain\Enums\TicketMessageType;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Events\TicketCreatedEvent;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * Open a new ticket: the ticket row, its first customer message, and any loose
 * references, all in one transaction. Publishes TicketCreatedEvent after commit
 * so the Notification module can alert the support team — this Action never
 * touches Notification itself.
 */
class CreateTicketAction
{
    /**
     * @param  list<array{type: string, code: ?string, id: ?int}>  $references
     * @param  list<int>  $mediaIds  pre-uploaded, caller-owned attachments (validated in the request)
     */
    public function handle(
        int $userId,
        string $subject,
        ?string $category,
        TicketPriority $priority,
        string $message,
        array $references = [],
        array $mediaIds = [],
    ): Ticket {
        return DB::transaction(function () use ($userId, $subject, $category, $priority, $message, $references, $mediaIds): Ticket {
            $ticket = Ticket::create([
                'user_id' => $userId,
                'assigned_to' => null,
                'subject' => $subject,
                'category' => $category,
                'priority' => $priority->value,
                'status' => TicketStatus::Open->value,
                'last_message_at' => now(),
            ]);

            $ticket->messages()->create([
                'user_id' => $userId,
                'message' => $message,
                'media_ids' => $mediaIds === [] ? null : array_values(array_map('intval', $mediaIds)),
                'type' => TicketMessageType::CustomerReply->value,
            ]);

            foreach ($references as $reference) {
                $ticket->references()->create([
                    'reference_type' => $reference['type'],
                    'reference_id' => $reference['id'] ?? null,
                    'reference_code' => $reference['code'] ?? null,
                    'snapshot' => null,
                ]);
            }

            // Dispatched inside the transaction: the listener implements
            // ShouldHandleEventsAfterCommit, so it fires only once this commits.
            Event::dispatch(new TicketCreatedEvent(
                ticketId: (int) $ticket->id,
                ticketNumber: (string) $ticket->ticket_number,
                customerUserId: $userId,
                subject: $subject,
                priority: $priority->value,
                category: $category,
            ));

            return $ticket;
        });
    }
}
