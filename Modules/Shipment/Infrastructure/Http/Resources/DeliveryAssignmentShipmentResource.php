<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Shipment\Domain\DTOs\DeliveryAssignmentShipmentDTO;

/** @mixin DeliveryAssignmentShipmentDTO */
class DeliveryAssignmentShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DeliveryAssignmentShipmentDTO $dto */
        $dto = $this->resource;

        return [
            // The courier's handle for the job is the shipment code. The order code
            // is deliberately absent: it is the customer's reference, and resolving
            // it would cost a cross-module lookup on every row of every page.
            'id' => $dto->publicCode,
            'status' => $dto->status->value,
            'status_label' => $dto->status->label(),
            // Carries latitude/longitude/map_address for new shipments so the
            // courier can navigate to the pin, not just to the written address.
            'address' => $dto->addressSnapshot,
            'delivery_slot' => $dto->deliverySlotSnapshot,
            'customer_name' => $dto->customerName,
            'customer_phone' => $dto->customerPhone,
            'receiver_name' => $dto->receiverName,
            'failure_reason' => $dto->failureReason,
            'note' => $dto->note,
            'assigned_at' => $dto->assignedAt?->toISOString(),
            'out_for_delivery_at' => $dto->outForDeliveryAt?->toISOString(),
            'delivered_at' => $dto->deliveredAt?->toISOString(),
        ];
    }
}
