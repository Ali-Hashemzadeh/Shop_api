<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Events;

/**
 * Published integration event: a *replacement* handoff code was issued for a
 * delivery already under way, and must reach the customer by SMS.
 *
 * The first code of an attempt does not use this event — it rides along with the
 * dispatch event [[ShipmentOutForDeliveryEvent]], so a dispatched local delivery
 * still produces exactly one customer SMS and one stored notification. This exists for
 * the recovery path (the SMS never arrived, the customer deleted it), which must
 * resend without inventing a second "your order is on its way" notification.
 *
 * The code travels as a primitive and is *transient*: the listener passes it to
 * the SMS template and nothing writes it down.
 */
class DeliveryVerificationCodeIssuedEvent
{
    public function __construct(
        public readonly int $shipmentId,
        public readonly int $orderId,
        /** The customer — the only person who ever learns the code. */
        public readonly int $userId,
        public readonly string $deliveryCode,
        public readonly ?string $orderPublicCode = null,
    ) {}
}
