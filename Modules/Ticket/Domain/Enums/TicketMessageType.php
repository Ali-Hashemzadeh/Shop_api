<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Enums;

/**
 * The origin/visibility of a single message in a ticket conversation.
 *
 * `internal_note` is the only staff-private type: it is never returned on any
 * customer-facing surface. `system_message` records an automatic event (status
 * change, assignment) and is shown to everyone.
 */
enum TicketMessageType: string
{
    case CustomerReply = 'customer_reply';
    case AdminReply = 'admin_reply';
    case InternalNote = 'internal_note';
    case SystemMessage = 'system_message';

    /**
     * The message types a customer is allowed to see — everything except the
     * staff-private internal note. Filtering happens at the query layer so a
     * private note can never leak into a customer response.
     *
     * @return list<string>
     */
    public static function customerVisible(): array
    {
        return [
            self::CustomerReply->value,
            self::AdminReply->value,
            self::SystemMessage->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
