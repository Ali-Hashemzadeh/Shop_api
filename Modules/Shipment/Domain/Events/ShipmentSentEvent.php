<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Events;

/**
 * Published integration event: the shipment is on its way to the customer.
 *
 * Raised for exactly the statuses the Shipment module already maps to the
 * order summary status `shipped` — `handed_to_post` (postal, carries a
 * tracking number) and `out_for_delivery` (local delivery, no tracking). No
 * new status is introduced. Carries primitives only.
 */
class ShipmentSentEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly ?string $trackingCode = null,
    ) {}
}
