<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Enums;

enum DeliverySlotStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
