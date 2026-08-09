<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Events;

/**
 * Published integration event: a postal parcel left the store and is now the
 * carrier's problem.
 *
 * The last thing this system knows about a postal shipment — the module
 * deliberately does not track the carrier's own lifecycle — so the message it
 * produces hands the customer a tracking number rather than an ETA.
 *
 * Split out from the retired generic "shipment sent" event because postal
 * handoff and local dispatch are different business moments with different
 * copy: one carries a tracking code, the other a handoff code. Primitives only.
 */
class ShipmentHandedToPostEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        /** Customer-facing order code (`bdo-XXXXXX`), alongside — not replacing — the id. */
        public readonly ?string $orderPublicCode = null,
        /** The postal tracking number recorded at handoff. */
        public readonly ?string $trackingCode = null,
    ) {}
}
