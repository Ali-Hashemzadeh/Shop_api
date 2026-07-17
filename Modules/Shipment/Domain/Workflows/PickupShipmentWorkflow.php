<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Workflows;

use Modules\Shipment\Domain\Enums\ShipmentStatus;

class PickupShipmentWorkflow extends AbstractShipmentWorkflow
{
    public function transitions(): array
    {
        return [
            ShipmentStatus::Pending->value => [
                ShipmentStatus::Preparing->value,
                ShipmentStatus::Cancelled->value,
            ],
            ShipmentStatus::Preparing->value => [
                ShipmentStatus::ReadyForPickup->value,
                ShipmentStatus::Cancelled->value,
            ],
            ShipmentStatus::ReadyForPickup->value => [
                ShipmentStatus::PickedUp->value,
            ],
            ShipmentStatus::PickedUp->value => [], // terminal
        ];
    }
}
