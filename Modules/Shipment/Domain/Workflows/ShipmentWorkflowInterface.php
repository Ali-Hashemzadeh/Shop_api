<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Workflows;

use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Exceptions\InvalidShipmentTransitionException;

interface ShipmentWorkflowInterface
{
    /**
     * @return array<string, string[]> map of from-status => allowed to-statuses
     */
    public function transitions(): array;

    public function canTransition(ShipmentStatus $from, ShipmentStatus $to): bool;

    /**
     * @throws InvalidShipmentTransitionException
     */
    public function assertCanTransition(ShipmentStatus $from, ShipmentStatus $to): void;
}
