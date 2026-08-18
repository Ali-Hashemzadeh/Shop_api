<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a delivery worker became responsible for a
 * local-delivery shipment.
 *
 * Raised only for a *real* change of assignee — re-assigning the driver who
 * already holds the shipment is idempotent and silent, so nobody is paged twice
 * for the same job. Carries primitives only.
 *
 * Deliberately absent: the customer's address and the customer's handoff code.
 * The courier gets the address from the authenticated driver API, and the code
 * belongs to the customer alone — a driver who could read it would not need the
 * customer to hand it over.
 */
class ShipmentAssignedToDeliveryEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $shipmentId,
        public readonly string $shipmentPublicCode,
        public readonly int $orderId,
        /** The newly responsible delivery worker — the audience of this event. */
        public readonly int $deliveryUserId,
        public readonly ?string $orderPublicCode = null,
        /** Delivery date (`Y-m-d`) from the frozen slot snapshot, when there is one. */
        public readonly ?string $deliveryDate = null,
        /** Slot window start (`H:i:s`) from the frozen slot snapshot. */
        public readonly ?string $deliveryStartsAt = null,
        public readonly ?string $deliveryEndsAt = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
