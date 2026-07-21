<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Events;

/**
 * Published integration event: a shipment reached the `delivered` status.
 *
 * Not raised for `picked_up` (in-store collection) — the customer is standing
 * at the counter, so a "your order was delivered" message is redundant. Add it
 * there only if the business asks for it. Carries primitives only.
 */
class ShipmentDeliveredEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
    ) {}
}
