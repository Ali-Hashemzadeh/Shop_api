<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Contracts;

use Modules\Shipment\Domain\DTOs\ShippingCostBreakdownDTO;
use Modules\Shipment\Domain\DTOs\ShippingCostRequestDTO;
use Modules\Shipment\Domain\Exceptions\PostalServiceUnavailableException;

/**
 * The postal pricing engine (Iran Post 1405). Keeps the Shipment module the sole
 * owner of shipping-cost calculation; a second carrier can be bound behind the same
 * contract later.
 */
interface ShippingCostCalculatorInterface
{
    /**
     * Price one postal service for one parcel and route, in integer TOMANS.
     *
     * @throws PostalServiceUnavailableException when the service is disabled or
     *                                           cannot be priced from approved data
     *                                           (fail-closed — never a fallback).
     */
    public function calculate(ShippingCostRequestDTO $request): ShippingCostBreakdownDTO;
}
