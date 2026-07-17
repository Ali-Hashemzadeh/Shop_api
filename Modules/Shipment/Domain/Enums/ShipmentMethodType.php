<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Enums;

enum ShipmentMethodType: string
{
    case Postal = 'postal';
    case LocalDelivery = 'local_delivery';
    case Pickup = 'pickup';
}
