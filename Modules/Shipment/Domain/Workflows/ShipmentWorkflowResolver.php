<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Workflows;

use InvalidArgumentException;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;

class ShipmentWorkflowResolver
{
    public function forType(string $methodType): ShipmentWorkflowInterface
    {
        return match (ShipmentMethodType::from($methodType)) {
            ShipmentMethodType::Postal => new PostalShipmentWorkflow,
            ShipmentMethodType::LocalDelivery => new LocalDeliveryShipmentWorkflow,
            ShipmentMethodType::Pickup => new PickupShipmentWorkflow,
        };
    }

    public function forMethodType(ShipmentMethodType $type): ShipmentWorkflowInterface
    {
        return $this->forType($type->value);
    }

    public function forTypeOrFail(string $methodType): ShipmentWorkflowInterface
    {
        if (ShipmentMethodType::tryFrom($methodType) === null) {
            throw new InvalidArgumentException("Unknown shipment method type: {$methodType}");
        }

        return $this->forType($methodType);
    }
}
