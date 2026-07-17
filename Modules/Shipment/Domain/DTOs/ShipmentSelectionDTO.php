<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

/**
 * Immutable, validated fulfillment selection produced by the Shipment module and
 * consumed by the Order module during checkout. Carries everything Order needs to
 * snapshot the selection without touching Shipment models.
 */
class ShipmentSelectionDTO
{
    public function __construct(
        public readonly string $methodCode,
        public readonly string $methodTitle,
        public readonly string $methodType,
        public readonly int $shippingCost,
        public readonly bool $requiresAddress,
        public readonly bool $requiresDeliverySlot,
        public readonly ?int $addressId = null,
        public readonly ?int $deliverySlotId = null,
        public readonly ?array $address = null,
        public readonly ?array $deliverySlot = null,
        public readonly ?array $pickupLocation = null,
    ) {}

    /**
     * Build the immutable shipment snapshot persisted on the Order.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'method_code' => $this->methodCode,
            'method_title' => $this->methodTitle,
            'method_type' => $this->methodType,
            'shipping_cost' => $this->shippingCost,
            'requires_address' => $this->requiresAddress,
            'address' => $this->address,
            'delivery_slot' => $this->deliverySlot,
            'pickup_location' => $this->pickupLocation,
        ];
    }
}
