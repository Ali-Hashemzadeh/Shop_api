<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Workflows;

use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Exceptions\InvalidShipmentTransitionException;

abstract class AbstractShipmentWorkflow implements ShipmentWorkflowInterface
{
    public function canTransition(ShipmentStatus $from, ShipmentStatus $to): bool
    {
        $allowed = $this->transitions()[$from->value] ?? [];

        return in_array($to->value, $allowed, true);
    }

    public function assertCanTransition(ShipmentStatus $from, ShipmentStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidShipmentTransitionException($from->value, $to->value);
        }
    }
}
