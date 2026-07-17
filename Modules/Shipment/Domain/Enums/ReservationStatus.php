<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Enums;

enum ReservationStatus: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case Released = 'released';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    /**
     * Statuses that consume bookable slot capacity.
     *
     * @return array<int, string>
     */
    public static function activeStatuses(): array
    {
        return [self::Held->value, self::Confirmed->value];
    }
}
