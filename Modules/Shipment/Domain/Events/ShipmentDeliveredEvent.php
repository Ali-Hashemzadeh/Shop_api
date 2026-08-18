<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a shipment reached the `delivered` status.
 *
 * Not raised for `picked_up` (in-store collection) — the customer is standing
 * at the counter, so a "your order was delivered" message is redundant. Add it
 * there only if the business asks for it. Carries primitives only.
 */
class ShipmentDeliveredEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        /** Customer-facing order code (`bdo-XXXXXX`), alongside — not replacing — the id. */
        public readonly ?string $orderPublicCode = null,
        public readonly ?int $shipmentId = null,
        public readonly ?string $method = null,
        public readonly ?int $driverId = null,
        public readonly ?int $deliveryMinutes = null,
        public readonly ?string $deliveredAt = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
