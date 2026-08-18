<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a delivery attempt failed.
 *
 * Carries primitives only.
 */
class ShipmentDeliveryFailedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly ?string $orderPublicCode = null,
        public readonly ?int $shipmentId = null,
        public readonly ?string $method = null,
        public readonly ?int $driverId = null,
        public readonly ?string $reason = null,
        public readonly ?string $failedAt = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
