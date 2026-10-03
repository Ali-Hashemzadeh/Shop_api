<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by a shipping calculator when no tariff matches the requested
 * (service, package, distance, weight) combination. The calculator itself is
 * fail-loud; the integration seam (EloquentShipmentManager) catches this and falls
 * back to the static method price so a mid-migration store never blocks checkout.
 */
class ShippingTariffNotFoundException extends RuntimeException {}
