<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Enums;

/**
 * Lifecycle of a support ticket.
 *
 * `open` is the state a ticket is born in. Staff drive it through
 * `in_progress` / `waiting_for_customer` / `answered`; either side may end it at
 * `closed`, and staff may `cancelled` an illegitimate one. A closed or cancelled
 * ticket is terminal for the customer (they must open a new one), but staff may
 * still reopen it by moving it to another status.
 */
enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Answered = 'answered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * Terminal states — a customer cannot reply to or re-close a ticket here.
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Closed, self::Cancelled];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
