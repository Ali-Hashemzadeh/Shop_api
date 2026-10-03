<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Enums;

/**
 * How urgently a ticket should be triaged. Customer-supplied on create; staff
 * may not need to change it, but the column allows it.
 */
enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
