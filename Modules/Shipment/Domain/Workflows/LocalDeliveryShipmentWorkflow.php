<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Workflows;

use Modules\Shipment\Domain\Enums\ShipmentStatus;

class LocalDeliveryShipmentWorkflow extends AbstractShipmentWorkflow
{
    public function transitions(): array
    {
        return [
            ShipmentStatus::Pending->value => [
                ShipmentStatus::Preparing->value,
                ShipmentStatus::Cancelled->value,
            ],
            ShipmentStatus::Preparing->value => [
                ShipmentStatus::ReadyForDispatch->value,
                ShipmentStatus::Cancelled->value,
            ],
            ShipmentStatus::ReadyForDispatch->value => [
                ShipmentStatus::OutForDelivery->value,
            ],
            ShipmentStatus::OutForDelivery->value => [
                ShipmentStatus::Delivered->value,
                ShipmentStatus::DeliveryFailed->value,
            ],
            // From a failed attempt only explicit business actions apply:
            // reschedule (→ ready_for_dispatch), retry (→ out_for_delivery), cancel.
            ShipmentStatus::DeliveryFailed->value => [
                ShipmentStatus::ReadyForDispatch->value,
                ShipmentStatus::OutForDelivery->value,
                ShipmentStatus::Cancelled->value,
            ],
            ShipmentStatus::Delivered->value => [], // terminal
        ];
    }
}
