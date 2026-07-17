<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

class ShipmentMethodDTO
{
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $type,
        public readonly int $price,
        public readonly bool $requiresAddress,
        public readonly bool $requiresDeliverySlot,
        public readonly bool $supportsTracking,
        public readonly ?int $estimatedMinDays,
        public readonly ?int $estimatedMaxDays,
        public readonly bool $available = true,
        public readonly ?string $unavailableReason = null,
        public readonly ?array $pickupLocation = null,
    ) {}
}
