<?php

declare(strict_types=1);

namespace Modules\Order\Domain\DTOs;

use Modules\Shipment\Domain\DTOs\ShipmentDTO;

/**
 * Aggregates an order with its current fulfillment state for the admin detail view.
 * The shipment is resolved through the Shipment contract (never a Shipment model) and
 * is null until the order is paid and a shipment record is activated.
 */
class AdminOrderDetailDTO
{
    public function __construct(
        public readonly OrderDTO $order,
        public readonly ?ShipmentDTO $shipment,
    ) {}
}
