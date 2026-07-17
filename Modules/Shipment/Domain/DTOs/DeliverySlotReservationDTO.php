<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

use Modules\Shipment\Domain\Models\DeliverySlotReservation;

class DeliverySlotReservationDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $deliverySlotId,
        public readonly int $orderId,
        public readonly int $userId,
        public readonly string $status,
    ) {}

    public static function fromModel(DeliverySlotReservation $reservation): self
    {
        return new self(
            id: $reservation->id,
            deliverySlotId: $reservation->delivery_slot_id,
            orderId: $reservation->order_id,
            userId: $reservation->user_id,
            status: $reservation->status,
        );
    }
}
