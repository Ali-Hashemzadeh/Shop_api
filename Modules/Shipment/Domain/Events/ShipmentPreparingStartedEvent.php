<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Events;

/**
 * Published integration event: a shipment entered the `preparing` status via
 * the single transition primitive. Carries primitives only.
 */
class ShipmentPreparingStartedEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
    ) {}
}
