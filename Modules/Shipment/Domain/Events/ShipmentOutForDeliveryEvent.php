<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Events;

/**
 * Published integration event: a local delivery is on the road with an assigned
 * courier.
 *
 * Raised on every entry to `out_for_delivery`, including the retry after a
 * `delivery_failed` attempt — each attempt is its own dispatch and its own code.
 *
 * Split out from the retired generic "shipment sent" event: this message must
 * carry the customer's handoff code, and a template that mentions a code must
 * never be used for a postal parcel that has none. Primitives only.
 */
class ShipmentOutForDeliveryEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        /** Customer-facing order code (`bdo-XXXXXX`), alongside — not replacing — the id. */
        public readonly ?string $orderPublicCode = null,
        /**
         * The freshly minted handoff code the customer must quote to the courier
         * on arrival.
         *
         * Transient by contract — it exists so the *one* dispatch SMS can carry it
         * instead of sending a second message, and it must never be persisted. The
         * listener passes it to the SMS template and leaves it out of the stored
         * notification payload.
         */
        public readonly ?string $deliveryCode = null,
    ) {}
}
