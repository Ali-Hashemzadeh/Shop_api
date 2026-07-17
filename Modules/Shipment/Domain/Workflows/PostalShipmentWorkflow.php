<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Workflows;

use Modules\Shipment\Domain\Enums\ShipmentStatus;

/**
 * Standard + express post share this workflow. Tracking ends at `handed_to_post`;
 * carrier lifecycle states (in_transit / out_for_delivery / delivered) are never
 * used for postal shipments in this version.
 */
class PostalShipmentWorkflow extends AbstractShipmentWorkflow
{
    public function transitions(): array
    {
        return [
            ShipmentStatus::Pending->value => [
                ShipmentStatus::Preparing->value,
                ShipmentStatus::Cancelled->value,
            ],
            ShipmentStatus::Preparing->value => [
                ShipmentStatus::ReadyForPost->value,
                ShipmentStatus::Cancelled->value,
            ],
            ShipmentStatus::ReadyForPost->value => [
                ShipmentStatus::HandedToPost->value,
            ],
            ShipmentStatus::HandedToPost->value => [], // terminal in v1
        ];
    }
}
