<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Contracts;

use Modules\Shipment\Domain\DTOs\ShippingCostBreakdownDTO;
use Modules\Shipment\Domain\DTOs\ShippingCostRequestDTO;
use Modules\Shipment\Domain\Exceptions\ShippingTariffNotFoundException;

/**
 * A pluggable shipping cost engine. The Post implementation is the first; a courier
 * or a second carrier can be bound behind the same contract later. Keeps the Shipment
 * module the sole owner of shipping-cost calculation.
 */
interface ShippingCostCalculatorInterface
{
    /**
     * Calculate the shipping cost (TOMANS) for the given request.
     *
     * @throws ShippingTariffNotFoundException
     *                                         when no tariff matches the request.
     */
    public function calculate(ShippingCostRequestDTO $request): ShippingCostBreakdownDTO;
}
