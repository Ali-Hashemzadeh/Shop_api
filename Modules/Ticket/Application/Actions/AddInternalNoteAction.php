<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Ticket\Application\Support\ResolvesMessageAttachments;
use Modules\Ticket\Domain\DTOs\TicketMessageDTO;
use Modules\Ticket\Domain\Enums\TicketMessageType;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * Record a staff-private note on a ticket. Deliberately raises no event and
 * does not touch `last_message_at`: an internal note is invisible to the
 * customer and must never trigger a customer notification or look like activity
 * on the customer's timeline. Its attachments are likewise never exposed to the
 * customer — the whole message is filtered out of customer responses.
 */
class AddInternalNoteAction
{
    use ResolvesMessageAttachments;

    public function __construct(
        private readonly MediaManagerInterface $media,
    ) {}

    /**
     * @param  list<int>  $mediaIds  pre-uploaded, caller-owned attachments (validated in the request)
     */
    public function handle(Ticket $ticket, int $authorUserId, string $message, array $mediaIds = []): TicketMessageDTO
    {
        $note = $ticket->messages()->create([
            'user_id' => $authorUserId,
            'message' => $message,
            'media_ids' => $mediaIds === [] ? null : array_values(array_map('intval', $mediaIds)),
            'type' => TicketMessageType::InternalNote->value,
        ]);

        return TicketMessageDTO::fromModel(
            $note,
            $this->resolveMessageAttachments($this->media, $note->media_ids),
        );
    }
}
