<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\DTOs;

use Carbon\Carbon;
use Modules\Media\Domain\DTOs\MediaDTO;
use Modules\Ticket\Domain\Enums\TicketMessageType;
use Modules\Ticket\Domain\Models\TicketMessage;

/**
 * Immutable view of one conversation message crossing the boundary.
 *
 * `attachments` are resolved MediaDTOs (batch-hydrated by the manager/action via
 * MediaManagerInterface) — raw media ids never cross the wall, same discipline
 * as ReviewDTO's resolved gallery URLs.
 */
class TicketMessageDTO
{
    /**
     * @param  list<MediaDTO>  $attachments
     */
    public function __construct(
        public readonly int $id,
        public readonly int $ticketId,
        public readonly ?int $userId,
        public readonly string $message,
        public readonly string $type,
        public readonly Carbon $createdAt,
        public readonly array $attachments = [],
    ) {}

    /**
     * @param  list<MediaDTO>  $attachments
     */
    public static function fromModel(TicketMessage $message, array $attachments = []): self
    {
        return new self(
            id: (int) $message->id,
            ticketId: (int) $message->ticket_id,
            userId: $message->user_id !== null ? (int) $message->user_id : null,
            message: (string) $message->message,
            type: $message->type instanceof TicketMessageType
                ? $message->type->value
                : (string) $message->type,
            createdAt: Carbon::parse($message->created_at),
            attachments: $attachments,
        );
    }
}
