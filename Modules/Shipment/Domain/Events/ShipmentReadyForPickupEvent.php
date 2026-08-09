<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Events;

/**
 * Published integration event: a pickup shipment is waiting at the counter.
 *
 * Raised on entry to `ready_for_pickup` only. `picked_up` stays silent — the
 * customer is standing there when it happens, so a second message about the
 * same visit would be noise. Carries primitives only.
 */
class ShipmentReadyForPickupEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        /** Customer-facing order code (`bdo-XXXXXX`), alongside — not replacing — the id. */
        public readonly ?string $orderPublicCode = null,
    ) {}
}
