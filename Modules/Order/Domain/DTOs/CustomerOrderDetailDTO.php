<?php

declare(strict_types=1);

namespace Modules\Order\Domain\DTOs;

use Modules\Payment\Domain\DTOs\PaymentDTO;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;

/**
 * Customer order-detail read aggregate. Cross-module state is carried only as
 * immutable DTOs; the Order DTO itself remains unchanged for existing flows.
 */
class CustomerOrderDetailDTO
{
    /**
     * @param  list<PaymentDTO>  $payments
     */
    public function __construct(
        public readonly OrderDTO $order,
        public readonly array $payments,
        public readonly ?ShipmentDTO $shipment,
    ) {}
}
