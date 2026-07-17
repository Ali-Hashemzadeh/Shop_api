<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;

/** @mixin ShipmentDTO */
class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ShipmentDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->publicCode,
            'order_id' => $dto->orderId,
            'method_code' => $dto->methodCode,
            'method_title' => $dto->methodTitle,
            'method_type' => $dto->methodType,
            'shipping_cost' => $dto->shippingCost,
            'status' => $dto->status->value,
            'status_label' => $dto->status->label(),
            'address' => $dto->addressSnapshot,
            'delivery_slot' => $dto->deliverySlotSnapshot,
            'pickup_location' => $dto->pickupLocationSnapshot,
            'carrier_name' => $dto->carrierName,
            'tracking_number' => $dto->trackingNumber,
            'receiver_name' => $dto->receiverName,
            'failure_reason' => $dto->failureReason,
            'note' => $dto->note,
            'handed_to_post_at' => $dto->handedToPostAt?->toISOString(),
            'out_for_delivery_at' => $dto->outForDeliveryAt?->toISOString(),
            'delivered_at' => $dto->deliveredAt?->toISOString(),
            'ready_for_pickup_at' => $dto->readyForPickupAt?->toISOString(),
            'picked_up_at' => $dto->pickedUpAt?->toISOString(),
            'created_at' => $dto->createdAt->toISOString(),
            'history' => ShipmentStatusHistoryResource::collection($dto->history),
        ];
    }
}
